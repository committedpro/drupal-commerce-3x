(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.hitpayWaitingReload = {
    attach: function (context) {
      const orderId =
				drupalSettings.commerceHitPayWaiting &&
				drupalSettings.commerceHitPayWaiting.orderId;

      if (!orderId) {
        return;
      }

      const storageKey =
        'hitpay_waiting_retry_count_' + orderId;

      once('hitpay-waiting-reload', 'body', context).forEach(function () {
        const maxRetries = 10;
        const delay = 2000;

        /**
         * Reads the retry counter from sessionStorage.
         *
         * @return {number|null}
         *   The stored retry count, or null when sessionStorage
         *   is unavailable.
         */
        function getRetryCount() {
          try {
            const value = sessionStorage.getItem(storageKey);

            if (value === null) {
              return 0;
            }

            const retryCount = parseInt(value, 10);

            if (!Number.isInteger(retryCount) || retryCount < 0) {
              return 0;
            }

            return retryCount;
          }
          catch (error) {
            return null;
          }
        }

        /**
         * Stores the retry counter in sessionStorage.
         *
         * @param {number} retryCount
         *   The retry count to store.
         *
         * @return {boolean}
         *   TRUE when stored successfully, FALSE otherwise.
         */
        function setRetryCount(retryCount) {
          try {
            sessionStorage.setItem(
              storageKey,
              String(retryCount)
            );

            return true;
          }
          catch (error) {
            return false;
          }
        }

        /**
         * Removes the retry counter from sessionStorage.
         *
         * @return {boolean}
         *   TRUE when removed successfully, FALSE otherwise.
         */
        function removeRetryCount() {
          try {
            sessionStorage.removeItem(storageKey);
            return true;
          }
          catch (error) {
            return false;
          }
        }

        /**
         * Stops polling and displays the waiting fallback.
         */
        function showFallback() {
          const waitingMessage = document.querySelector(
            '.hitpay-waiting-message'
          );

          if (waitingMessage) {
            waitingMessage.hidden = true;
          }

          const fallbackBox = document.getElementById(
            'hitpay-waiting-fallback'
          );

          if (fallbackBox) {
            fallbackBox.hidden = false;
          }
        }

        const currentRetry = getRetryCount();

        // sessionStorage is unavailable.
        // Stop polling to prevent an unlimited reload loop.
        if (currentRetry === null) {
          showFallback();
          return;
        }

        if (currentRetry >= maxRetries) {
          // Allow a later manual refresh to start a fresh waiting cycle.
          removeRetryCount();

          showFallback();
          return;
        }

        // If the updated counter cannot be persisted, stop polling.
        if (!setRetryCount(currentRetry + 1)) {
          showFallback();
          return;
        }

        window.setTimeout(function () {
          window.location.reload();
        }, delay);
      });
    }
  };

})(Drupal, drupalSettings, once);