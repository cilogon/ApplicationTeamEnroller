<?php
/**
 * COmanage Registry Application Team Enroller Identity Snapshot
 *
 * Builds the identity snapshot the response page keeps in the session
 * (KTD5), in the shape AteEnrollmentRequest::normalizeSnapshot() defines:
 *
 * - identifier: the login, Auth.User.username
 * - identifier_type: the CO's configured login identifier type
 * - emails: the values of the configured environment variables, each also
 *   read with Apache's REDIRECT_ prefix (as Access01Enroller reads
 *   REDIRECT_OIDC_CLAIM_email), then the verified EmailAddress rows on the
 *   OrgIdentities that carry the login. Unverified rows never count.
 * - name: the display name, if one is present
 * - errors: every lookup that failed, each of which makes the snapshot a
 *   mismatch when it is evaluated
 *
 * The request environment belongs to whoever is logged in, so the snapshot
 * is built only on the response page, for the responder.
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('AteEnrollmentRequest', 'ApplicationTeamEnroller.Model');

class AteIdentitySnapshot {
  // Apache prefixes a variable with REDIRECT_ when the request was
  // internally redirected, for example through a rewrite rule.
  const RedirectPrefix = 'REDIRECT_';

  /**
   * Build a normalized identity snapshot for a login (KTD5).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer              $coId       CO ID
   * @param  String               $identifier Login identifier (Auth.User.username)
   * @param  Array                $settings   The CO's AteSetting fields (login_identifier_type, email_env_vars)
   * @param  AteEnrollmentRequest $Request    Model providing verifiedLoginEmails()
   * @param  Array                $env        Environment to read, or null for the web server's (env())
   * @param  String               $name       Display name, or null
   * @return Array                            Snapshot, as AteEnrollmentRequest::normalizeSnapshot() returns it
   * @throws InvalidArgumentException If there is no identifier
   */

  public static function build($coId, $identifier, $settings, $Request, $env = null, $name = null) {
    if(!is_string($identifier) || trim($identifier) === '') {
      throw new InvalidArgumentException(_txt('pl.applicationteamenroller.er.snapshot.identifier'));
    }

    $emails = self::environmentEmails(self::variableNames($settings['email_env_vars'] ?? ''), $env);
    $errors = array();

    try {
      $emails = array_merge($emails, $Request->verifiedLoginEmails($coId, $identifier));
    } catch(Exception $e) {
      $errors[] = 'verified login emails: ' . $e->getMessage();
    }

    return AteEnrollmentRequest::normalizeSnapshot(array(
      'identifier'      => $identifier,
      'identifier_type' => $settings['login_identifier_type'] ?? null,
      'emails'          => $emails,
      'name'            => $name,
      'errors'          => $errors
    ));
  }

  /**
   * The configured environment variable names: a comma-separated list.
   * Names that are not plain variable names are ignored, and so are names
   * a client controls (headerDerived()).
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $list Comma-separated names
   * @return Array        Names
   */

  public static function variableNames($list) {
    $ret = array();

    foreach(explode(',', (string)$list) as $n) {
      $n = trim($n);

      if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n) && !self::headerDerived($n)) {
        $ret[] = $n;
      }
    }

    return $ret;
  }

  /**
   * Whether a variable name is one the web server fills from a client
   * request header: HTTP_*, also behind any number of REDIRECT_ prefixes.
   * A responder could put any address there, so it never counts as a
   * login's email.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String $name Variable name
   * @return Boolean
   */

  public static function headerDerived($name) {
    $n = strtoupper(trim((string)$name));

    while(strpos($n, self::RedirectPrefix) === 0) {
      $n = substr($n, strlen(self::RedirectPrefix));
    }

    return strpos($n, 'HTTP_') === 0;
  }

  /**
   * The addresses carried by the named variables, each read as is and with
   * the REDIRECT_ prefix. A value may carry several addresses separated by
   * commas, semicolons, or whitespace (mod_auth_openidc joins a
   * multi-valued claim with commas).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array $names Variable names
   * @param  Array $env   Environment to read, or null for env()
   * @return Array        Addresses, in the order found (not deduplicated)
   */

  public static function environmentEmails($names, $env = null) {
    $ret = array();

    foreach($names as $n) {
      foreach(array($n, self::RedirectPrefix . $n) as $var) {
        $v = is_array($env) ? ($env[$var] ?? null) : env($var);

        if(!is_string($v) || trim($v) === '') {
          continue;
        }

        foreach(preg_split('/[\s,;]+/', $v, -1, PREG_SPLIT_NO_EMPTY) as $mail) {
          $ret[] = $mail;
        }
      }
    }

    return $ret;
  }

  /**
   * A display name from Registry's session name (Auth.User.name, a
   * PrimaryName array) or a string.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Mixed  $name Name array, string, or null
   * @return String       Display name, or null
   */

  public static function displayName($name) {
    if(is_array($name)) {
      $parts = array();

      foreach(array('honorific', 'given', 'middle', 'family', 'suffix') as $k) {
        if(!empty($name[$k]) && is_string($name[$k]) && trim($name[$k]) !== '') {
          $parts[] = trim($name[$k]);
        }
      }

      $name = implode(' ', $parts);
    }

    return (is_string($name) && trim($name) !== '') ? trim($name) : null;
  }
}
