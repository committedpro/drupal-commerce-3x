<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Controller;

use Drupal\commerce_hitpay\Service\WebhookProcessor;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Receives HitPay webhooks.
 */
final class WebhookController extends ControllerBase {

  /**
   * Constructs a WebhookController object.
   */
  public function __construct(
    private readonly WebhookProcessor $webhookProcessor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('commerce_hitpay.webhook_processor'),
    );
  }

  /**
   * Handles incoming HitPay webhooks.
   */
  public function handleWebhook(
	  Request $request,
	  PaymentGatewayInterface $commerce_payment_gateway,
	): JsonResponse {
	  $configuration = $commerce_payment_gateway
			->getPlugin()
			->getConfiguration();

	  $webhookSalt = $configuration['webhook_salt'] ?? '';

	  return $this->webhookProcessor->process(
			$request,
			$webhookSalt,
			$commerce_payment_gateway
	  );
	}
}