<?php

namespace Drupal\csc_admin\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drush command to smoke-test key front-end pages.
 *
 * Intended to be run right after a Drupal core/module update (on dev or
 * production) to catch pages that break silently — e.g. the recurring
 * event pages that failed to load after the core update in July 2026.
 */
class CscPageCheckCommands extends DrushCommands {

  /**
   * Content types that intentionally redirect away when viewed directly.
   *
   * @see csc_general — slide_image nodes redirect to the homepage.
   */
  const SKIP_BUNDLES = ['slide_image'];

  /**
   * Strings that indicate a broken page even when it returns HTTP 200.
   */
  const ERROR_MARKERS = [
    'The website encountered an unexpected error',
    'Fatal error',
    'Uncaught Error',
    'Recoverable fatal error',
  ];

  /**
   * A themed page smaller than this is almost certainly broken/empty.
   */
  const MIN_RESPONSE_BYTES = 500;

  /**
   * Landing pages for each top nav item — checked explicitly since they
   * aren't necessarily the most-recently-changed node/view of their type.
   */
  const TOP_NAV_PATHS = [
    '/commons',
    '/events',
    '/conferences',
    '/research',
    '/students',
    '/about',
    '/contact',
  ];

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected ClientInterface $httpClient;
  protected RequestStack $requestStack;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, ClientInterface $http_client, RequestStack $request_stack) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->httpClient = $http_client;
    $this->requestStack = $request_stack;
  }

  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('http_client'),
      $container->get('request_stack')
    );
  }

  /**
   * Load the homepage, one node per content type, several recurring
   * events, and every enabled Views page display, and report any that
   * fail to load.
   *
   * @command csc-admin:page-check
   * @aliases page-check
   * @option base-url Base URL to test against. Defaults to the host used to invoke this command (respects --uri).
   * @option recurring-events Number of recurring events to check. Defaults to 3.
   * @usage drush page-check
   *   Check the site this command is running against.
   * @usage drush --uri=https://dev.csc.virginia.edu page-check
   *   Check dev after deploying an update, before touching production.
   * @usage drush page-check --base-url=https://csc.virginia.edu
   *   Explicitly target production regardless of --uri.
   */
  public function pageCheck(array $options = ['base-url' => NULL, 'recurring-events' => 3]) {
    $base_url = $options['base-url'] ?: $this->getDefaultBaseUrl();

    // The node/event/view paths below are discovered by querying whichever
    // database this command is connected to. If --base-url points at a
    // different environment than the one this command is running against,
    // you'll be testing that environment's content against this one's
    // URLs — any content that only exists on one side will show up as a
    // false failure. Run this command on each environment separately
    // (e.g. via its own drush alias/SSH session) rather than relying on
    // --base-url to reach across environments.
    if (rtrim($base_url, '/') !== rtrim($this->getDefaultBaseUrl(), '/')) {
      $this->io()->warning("Testing $base_url using content discovered from this site's own database. Pages that exist on one environment but not the other will show up as false failures — run this command on each environment separately for a reliable check.");
    }

    $checks = $this->buildChecklist((int) $options['recurring-events']);

    if (empty($checks)) {
      $this->io()->warning('No pages found to check.');
      return;
    }

    $rows = [];
    $failures = 0;

    $progress = new ProgressBar($this->output(), count($checks));
    $progress->setFormat(' %current%/%max% pages checked -- %message%');
    $progress->setMessage('starting...');
    $progress->start();

    foreach ($checks as $check) {
      $progress->setMessage($check['label']);
      $url = rtrim($base_url, '/') . $check['path'];
      [$status, $note, $ms] = $this->fetch($url);
      $ok = $note === 'OK';
      $failures += $ok ? 0 : 1;

      $rows[] = [
        $ok ? 'OK' : 'FAIL',
        $check['label'],
        $check['path'],
        $status,
        $ms === NULL ? '-' : "{$ms} ms",
        $note,
      ];
      $progress->advance();
    }

    $progress->finish();
    $this->output()->writeln('');
    $this->output()->writeln('');

    $this->io()->table(['', 'Check', 'Path', 'Status', 'Time', 'Notes'], $rows);

    if ($failures) {
      throw new \Exception("$failures of " . count($checks) . " page(s) failed to load correctly.");
    }

    $this->io()->success('All ' . count($checks) . ' page(s) loaded successfully.');
  }

  /**
   * Assemble the list of paths to check, deduplicated.
   */
  protected function buildChecklist(int $recurring_event_limit): array {
    $checks = [];
    $seen_paths = [];

    $add = function (string $label, string $path) use (&$checks, &$seen_paths) {
      $path = '/' . ltrim($path, '/');
      if (isset($seen_paths[$path])) {
        return;
      }
      $seen_paths[$path] = TRUE;
      $checks[] = ['label' => $label, 'path' => $path];
    };

    $add('Front page', '/');

    foreach (self::TOP_NAV_PATHS as $path) {
      $add("Top nav: {$path}", $path);
    }

    foreach ($this->getLatestNodePerBundle() as $bundle => $node) {
      $add("Node ({$bundle}): {$node->label()}", $node->toUrl()->toString());
    }

    $recurring_found = 0;
    foreach ($this->getRecurringEvents($recurring_event_limit) as $node) {
      $add("Recurring event: {$node->label()}", $node->toUrl()->toString());
      $recurring_found++;
    }
    if (!$recurring_found) {
      $this->io()->note('No recurring events found in the database to check.');
    }

    foreach ($this->getViewsPagePaths() as $label => $path) {
      $add($label, $path);
    }

    return $checks;
  }

  /**
   * Most recently changed published node for each content type.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by bundle.
   */
  protected function getLatestNodePerBundle(): array {
    $nodes = [];
    $node_storage = $this->entityTypeManager->getStorage('node');
    $bundles = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach (array_keys($bundles) as $bundle) {
      if (in_array($bundle, self::SKIP_BUNDLES, TRUE)) {
        continue;
      }
      $nids = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->sort('changed', 'DESC')
        ->range(0, 1)
        ->execute();
      if ($nids) {
        $nodes[$bundle] = $node_storage->load(reset($nids));
      }
    }

    return $nodes;
  }

  /**
   * Published event nodes with more than one field_date instance.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function getRecurringEvents(int $limit): array {
    if ($limit <= 0) {
      return [];
    }

    $query = $this->database->select('node__field_date', 'fd');
    $query->join('node_field_data', 'n', 'n.nid = fd.entity_id');
    $query->condition('n.type', 'event');
    $query->condition('n.status', 1);
    $query->fields('fd', ['entity_id']);
    $query->groupBy('fd.entity_id');
    $query->groupBy('n.changed');
    $query->having('COUNT(fd.entity_id) > 1');
    $query->orderBy('n.changed', 'DESC');
    $query->range(0, $limit);
    $nids = $query->execute()->fetchCol();

    return $nids ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];
  }

  /**
   * Paths for every enabled Views "page" display we can hit anonymously.
   *
   * @return string[]
   *   Path keyed by a human-readable label.
   */
  protected function getViewsPagePaths(): array {
    $paths = [];
    $anonymous = new AnonymousUserSession();
    $views = $this->entityTypeManager->getStorage('view')->loadMultiple();

    foreach ($views as $view_id => $view) {
      if (!$view->status()) {
        continue;
      }
      $executable = $view->getExecutable();
      foreach ($view->get('display') as $display_id => $display) {
        if (($display['display_plugin'] ?? NULL) !== 'page') {
          continue;
        }
        $path = $display['display_options']['path'] ?? NULL;
        // Paths with a % argument segment can't be resolved generically,
        // admin paths (e.g. the media library widget) are gated by
        // route-level access that the view's own access plugin doesn't
        // reflect, and "-old" displays are deprecated leftovers that
        // aren't worth checking.
        if (!$path || str_contains($path, '%') || str_starts_with($path, 'admin/') || str_ends_with($path, '-old')) {
          continue;
        }
        // Skip anything else an anonymous visitor couldn't reach anyway
        // (e.g. role-restricted listings) — not relevant to a public
        // smoke test.
        if (!$executable->access($display_id, $anonymous)) {
          continue;
        }
        $paths["View: {$view_id} ({$display_id})"] = $path;
      }
    }

    return $paths;
  }

  /**
   * The scheme + host to test against, honoring --uri.
   */
  protected function getDefaultBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request ? $request->getSchemeAndHttpHost() : 'http://localhost';
  }

  /**
   * Fetch a URL and classify the result.
   *
   * @return array{0: int, 1: string, 2: ?int}
   *   [HTTP status (0 on connection failure), note, milliseconds].
   */
  protected function fetch(string $url): array {
    $start = microtime(TRUE);
    try {
      $response = $this->httpClient->request('GET', $url, [
        'http_errors' => FALSE,
        'timeout' => 20,
        'headers' => ['Cache-Control' => 'no-cache'],
      ]);
    }
    catch (\Throwable $e) {
      return [0, 'Connection failed: ' . $e->getMessage(), NULL];
    }
    $ms = (int) round((microtime(TRUE) - $start) * 1000);

    $status = $response->getStatusCode();
    if ($status !== 200) {
      return [$status, "HTTP {$status}", $ms];
    }

    $body = (string) $response->getBody();
    foreach (self::ERROR_MARKERS as $marker) {
      if (str_contains($body, $marker)) {
        return [$status, "Error text found on page: \"{$marker}\"", $ms];
      }
    }
    if (strlen($body) < self::MIN_RESPONSE_BYTES) {
      return [$status, 'Response body suspiciously short (' . strlen($body) . ' bytes) — page may be blank', $ms];
    }

    return [$status, 'OK', $ms];
  }

}
