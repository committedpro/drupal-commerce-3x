<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Service;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;

/**
 * Factory for creating configured HitPay clients.
 */
final class HitPayClientFactory implements HitPayClientFactoryInterface {

  /**
   * Constructs a HitPayClientFactory object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
	 *
	 * @param \Drupal\Core\Logger\LoggerChannelInterface
	 *   The logger channel.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
		private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fromPaymentGateway(
    PaymentGatewayInterface $paymentGateway,
  ): HitPayClient {

    $configuration = $paymentGateway
      ->getPlugin()
      ->getConfiguration();

    return $this->create(
      $configuration['api_key'],
      $configuration['mode'] === 'test',
		  !empty($configuration['debug'])
    );
  }

  /**
   * {@inheritdoc}
   */
  public function create(
    string $apiKey,
    bool $sandbox,
		bool $debug = FALSE,
  ): HitPayClient {
    return new HitPayClient(
      $this->httpClient,
			$this->logger,
      $apiKey,
      $sandbox,
			$debug
    );
  }
}