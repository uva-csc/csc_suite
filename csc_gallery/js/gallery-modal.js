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
        var prevButton = modalEl.querySelector('.csc-gallery-modal-prev');
        var nextButton = modalEl.querySelector('.csc-gallery-modal-next');
        var triggers = Array.prototype.slice.call(
          document.querySelectorAll('[data-bs-target="#' + modalEl.id + '"]')
        );
        var currentIndex = -1;

        function showAt(index) {
          if (!triggers.length) {
            return;
          }
          currentIndex = (index + triggers.length) % triggers.length;
          var trigger = triggers[currentIndex];
          image.src = trigger.getAttribute('data-full-src') || '';
          image.alt = trigger.getAttribute('data-alt') || '';
          caption.textContent = trigger.getAttribute('data-caption') || '';
        }

        modalEl.addEventListener('show.bs.modal', function (event) {
          var trigger = event.relatedTarget;
          if (!trigger) {
            return;
          }
          showAt(triggers.indexOf(trigger));
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
          image.src = '';
          caption.textContent = '';
          currentIndex = -1;
        });

        if (prevButton) {
          prevButton.addEventListener('click', function () {
            showAt(currentIndex - 1);
          });
        }

        if (nextButton) {
          nextButton.addEventListener('click', function () {
            showAt(currentIndex + 1);
          });
        }

        modalEl.addEventListener('keydown', function (event) {
          if (event.key === 'ArrowLeft') {
            showAt(currentIndex - 1);
          }
          else if (event.key === 'ArrowRight') {
            showAt(currentIndex + 1);
          }
        });
      });
    }
  };

})(Drupal, once);
