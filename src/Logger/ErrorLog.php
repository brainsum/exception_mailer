<?php

namespace Drupal\exception_mailer\Logger;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\exception_mailer\Utility\UserRepository;
use Drupal\Component\Utility\Xss;

/**
 * Class ErrorLog.
 *
 * Selects errors corresponding levels specified in config to send emails.
 */
class ErrorLog implements LoggerInterface {
  use RfcLoggerTrait;
  use DependencySerializationTrait;

  /**
   * The message's placeholders parser.
   *
   * @var \Drupal\Core\Logger\LogMessageParserInterface
   */
  protected $parser;

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
   * Constructs a new ErrorLog object.
   *
   * @param \Drupal\Core\Logger\LogMessageParserInterface $parser
   *   The parser to use when extracting message variables.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue service.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue manager.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The configuration factory.
   */
  public function __construct(
    LogMessageParserInterface $parser,
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_manager,
    ConfigFactory $configFactory
  ) {
    $this->parser = $parser;
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) {
    // Remove any backtraces since they may contain an unserializable variable.
    unset($context['backtrace']);

    // Convert PSR3-style messages to \Drupal\Component\Render\FormattableMarkup
    // style, so they can be translated too in runtime.
    $message_placeholders = $this->parser->parseMessagePlaceholders($message, $context);

    $level_types = $this->configFactory->get('exception_mailer.settings')->get('level_type');
    $level_types = array_filter($level_types);

    $levels = [];
    foreach ($level_types as $level_type) {
      switch ($level_type) {
        case 'EMERGENCY':
          $levels['EMERGENCY'] = RfcLogLevel::EMERGENCY;
          break;

        case 'ALERT':
          $levels['ALERT'] = RfcLogLevel::ALERT;
          break;

        case 'CRITICAL':
          $levels['CRITICAL'] = RfcLogLevel::CRITICAL;
          break;

        case 'ERROR':
          $levels['ERROR'] = RfcLogLevel::ERROR;
          break;

        case 'WARNING':
          $levels['WARNING'] = RfcLogLevel::WARNING;
          break;

        case 'NOTICE':
          $levels['NOTICE'] = RfcLogLevel::NOTICE;
          break;

        case 'INFO':
          $levels['INFO'] = RfcLogLevel::INFO;
          break;

        case 'DEBUG':
          $levels['DEBUG'] = RfcLogLevel::DEBUG;
          break;
      }
    }

    if (empty($levels)) {
      return;
    }

    $queue = $this->queueFactory->get('manual_exception_email', TRUE);
    $queue_worker = $this->queueManager->createInstance('manual_exception_email');

    if (\in_array($level, array_values($levels), TRUE)) {
      foreach (UserRepository::getUserEmails('administrator') as $admin) {
        $data['email'] = $admin;
        $data['exception'] = '';
        $data['message'] = t(Xss::filterAdmin($message), $message_placeholders);
        $data['site'] = $this->configFactory->get('system.site')->get('name');
        $data['timestamp'] = $context['timestamp'];
        $data['user'] = $context['uid'];
        $data['severity'] = array_search($level, $levels, TRUE);
        $data['type'] = mb_substr($context['channel'], 0, 64);
        $data['link'] = $context['link'];
        $data['location'] = $context['request_uri'];
        $data['referrer'] = $context['referer'];
        $data['hostname'] = mb_substr($context['ip'], 0, 128);
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
    }
  }

}
