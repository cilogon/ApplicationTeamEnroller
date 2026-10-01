<?php
/**
 * Test-only CakeEmail transport for the ApplicationTeamEnroller thin runner.
 *
 * Invitation email goes through CakeEmail exactly as in production (KTD13);
 * only the transport differs. A test points AteInvitation::$emailConfig at
 * AteRecordingTransport::emailConfig(), and every message the plugin sends is
 * recorded here instead of reaching a mail server. Setting $fail makes the
 * next send throw, as an unreachable SMTP server would.
 *
 * CakeEmail resolves the transport by class name ('AteRecording' ->
 * AteRecordingTransport); the class is already declared when the runner loads
 * Test/lib, so no file lookup happens.
 *
 * Loaded by Console/Command/AteTestShell.php along with every other Test/lib
 * file, so this file must have no side effects at load time.
 */

App::uses('AbstractTransport', 'Network/Email');
App::uses('CakeEmail', 'Network/Email');

class AteRecordingTransport extends AbstractTransport {

  /** @var array Messages sent, each array('to', 'subject', 'body'). */
  public static $sent = array();

  /** @var boolean When true, send() throws instead of recording. */
  public static $fail = false;

  /** Forget recorded messages and stop failing. */
  public static function reset() {
    self::$sent = array();
    self::$fail = false;
  }

  /**
   * A CakeEmail configuration that uses this transport.
   *
   * @return Array
   */
  public static function emailConfig() {
    return array(
      'transport' => 'AteRecording',
      'from' => 'registry@example.org',
      'charset' => 'utf-8',
      'headerCharset' => 'utf-8'
    );
  }

  /**
   * Record the message, or throw if $fail is set.
   *
   * @param  CakeEmail $email Email to send
   * @return Array            Headers and message, as the Debug transport returns
   * @throws SocketException  If $fail is set
   */
  public function send(CakeEmail $email) {
    if(self::$fail) {
      throw new SocketException('hermetic mail transport failure');
    }

    $body = implode("\n", (array)$email->message());

    self::$sent[] = array(
      'to' => array_keys($email->to()),
      'subject' => $email->subject(),
      'body' => $body
    );

    return array('headers' => '', 'message' => $body);
  }
}
