<?php

namespace Drupal\exception_mailer\Subscribers;

use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\exception_mailer\Utility\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Subscribe to thrown exceptions to send emails to admin users.
 */
class ExceptionEventSubscriber implements EventSubscriberInterface {

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The queue manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *  The queue service.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue manager.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger,
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_manager,
    ConfigFactory $configFactory,
    AccountInterface $account,
    DateFormatterInterface $dateFormatter,
    TimeInterface $time
  ) {
    $this->logger = $logger;
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
    $this->configFactory = $configFactory;
    $this->currentUser = $account;
    $this->dateFormatter = $dateFormatter;
    $this->time = $time;
  }

  /**
   * Event handler.
   *
   * @param Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event
   *   The exception event.
   */
  public function onException(GetResponseForExceptionEvent $event) {
    $request = $event->getRequest();
    $date = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'j M Y - G:i T', drupal_get_user_timezone());

    $exception = $event->getException();
    $queue = $this->queueFactory->get('manual_exception_email', TRUE);
    $queue_worker = $this->queueManager->createInstance('manual_exception_email');
    if (!$exception instanceof FormAjaxException && !$exception instanceof NotFoundHttpException) {
      foreach (UserRepository::getUserEmails("administrator") as $admin) {
        $data['email'] = $admin;
        $data['exception'] = get_class($exception);
        $data['message'] = $exception->getMessage() . "\n" . $exception->getTraceAsString();
        $data['site'] = $this->configFactory->get('system.site')->get('name');
        $data['date'] = $date;
        $data['user'] = $this->currentUser->getDisplayName();
        $data['location'] = $request->getUri();
        $data['referrer'] = $request->headers->get('Referer', '');
        $data['hostname'] = $request->getClientIp();
        $queue->createItem($data);
      }
      while ($item = $queue->claimItem()) {
        try {
          $queue_worker->processItem($item->data);
          $queue->deleteItem($item);
        }
        catch (SuspendQueueException $e) {
          $queue->releaseItem($item);
          break;
        }
        catch (\Exception $e) {
          watchdog_exception('exception_mailer', $e);
        }
      }
      $this->logger->get('php')->error($exception->getMessage());
      $response = new Response($exception->getMessage(), 500);
      $event->setResponse($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION][] = ['onException', 60];
    return $events;
  }

}
