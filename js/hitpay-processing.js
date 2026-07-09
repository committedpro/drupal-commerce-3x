(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.hitpayProcessingReload = {
    attach: function (context) {
      const paymentRequestId =
				drupalSettings.commerceHitPay &&
				drupalSettings.commerceHitPay.paymentRequestId;

      if (!paymentRequestId) {
        return;
      }

      const storageKey =
        'hitpay_retry_count_' + paymentRequestId;

      once('hitpay-reload', 'body', context).forEach(function () {
        const maxRetries = 10;
        const delay = 4000;

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
         * Stops polling and displays the timeout fallback.
         */
        function showFallback() {
          const statusMsg = document.querySelector(
            '.hitpay-processing-message'
          );

          if (statusMsg) {
            statusMsg.hidden = true;
          }

          const fallbackBox = document.getElementById(
            'hitpay-timeout-fallback'
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
          // Keep the timeout state persistent for this payment request.
          setRetryCount(maxRetries);

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