/**
 * @file
 * Wires each CSC Gallery modal to whichever tile triggered it.
 */

(function (Drupal, once) {

  'use strict';

  Drupal.behaviors.cscGalleryModal = {
    attach: function (context) {
      once('csc-gallery-modal', '.csc-gallery-modal', context).forEach(function (modalEl) {
        var image = modalEl.querySelector('.csc-gallery-modal-image');
        var caption = modalEl.querySelector('.csc-gallery-modal-caption');

        modalEl.addEventListener('show.bs.modal', function (event) {
          var trigger = event.relatedTarget;
          if (!trigger) {
            return;
          }
          image.src = trigger.getAttribute('data-full-src') || '';
          image.alt = trigger.getAttribute('data-alt') || '';
          caption.textContent = trigger.getAttribute('data-caption') || '';
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
          image.src = '';
          caption.textContent = '';
        });
      });
    }
  };

})(Drupal, once);
