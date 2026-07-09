<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Service;

/**
 * Verifies HitPay webhook signatures.
 */
final class SignatureVerifier {

  /**
   * Verify a webhook signature.
   *
   * @param string $payload
   *   Raw request body.
   * @param string $signature
   *   Signature received from HitPay.
   * @param string $webhookSalt
   *   Configured webhook salt.
   *
   * @return bool
   *   TRUE if valid.
   */
  public function verify(
    string $payload,
    string $signature,
    string $webhookSalt,
  ): bool {

    if ($payload === '' || $signature === '' || $webhookSalt === '') {
      return FALSE;
    }
		
		$signature = trim($signature);
		$webhookSalt = trim($webhookSalt);

    $expectedSignature = hash_hmac(
      'sha256',
      $payload,
      $webhookSalt,
    );

		return hash_equals(
			$expectedSignature,
			$signature,
		);
  }

}