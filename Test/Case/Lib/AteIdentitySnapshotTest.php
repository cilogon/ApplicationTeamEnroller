<?php
/**
 * U8: building the identity snapshot on the response page (KTD5). The login
 * identifier is Auth.User.username; emails come from the configured
 * environment variables, each also read with Apache's REDIRECT_ prefix, plus
 * the verified EmailAddress rows on OrgIdentities carrying the login.
 * Unverified rows never count, and a failed lookup is recorded as an error,
 * which makes the snapshot a mismatch.
 *
 * Uses the engine world (Test/lib/AteEngineTestCase.php), whose CO has login
 * identifier type 'eppn'.
 */

App::uses('AteIdentitySnapshot', 'ApplicationTeamEnroller.Lib');

class AteIdentitySnapshotTest extends AteEngineTestCase {

  /** The CO's settings row as the controller reads it. */
  private function settings($overrides = array()) {
    return $overrides + array(
      'login_identifier_type' => 'eppn',
      'email_env_vars' => 'OIDC_CLAIM_email'
    );
  }

  /** The configured variable is read, and so is its REDIRECT_ form. */
  public function testReadsConfiguredVariableAndRedirectForm() {
    $sub = $this->sub('snap-env');

    $snap = AteIdentitySnapshot::build($this->coId, $sub, $this->settings(), $this->Req,
                                       array('OIDC_CLAIM_email' => self::Invited), 'Pat R');

    $this->assertEqual($sub, $snap['identifier'], 'identifier is the login');
    $this->assertEqual('eppn', $snap['identifier_type'], 'type is the configured login identifier type');
    $this->assertEqual(array(self::Invited), $snap['emails'], 'plain variable read');
    $this->assertEqual('Pat R', $snap['name'], 'name kept');
    $this->assertEqual(array(), $snap['errors'], 'no errors');

    $snap = AteIdentitySnapshot::build($this->coId, $sub, $this->settings(), $this->Req,
                                       array('REDIRECT_OIDC_CLAIM_email' => self::Other));

    $this->assertEqual(array(self::Other), $snap['emails'], 'REDIRECT_ form read');
    $this->assertNull($snap['name'], 'no name when none is present');
  }

  /** Several configured variables, several values, duplicates folded. */
  public function testSeveralVariablesAndValues() {
    $snap = AteIdentitySnapshot::build($this->coId, $this->sub('snap-multi'),
      $this->settings(array('email_env_vars' => 'OIDC_CLAIM_email, MAIL ,bad name')), $this->Req,
      array(
        'OIDC_CLAIM_email' => self::Invited . ',' . strtoupper(self::Invited),
        'REDIRECT_MAIL' => self::Third . ';' . self::Other,
        'bad name' => 'never@read.example'
      ));

    $this->assertEqual(array(self::Invited, self::Third, self::Other), $snap['emails'],
      'every configured variable, split, deduplicated case-insensitively, invalid names ignored');
  }

  /**
   * Security: variables filled from client request headers (HTTP_*, and
   * their REDIRECT_ form) are never read, even if configured.
   */
  public function testHttpVariablesAreIgnored() {
    $this->assertEqual(array('OIDC_CLAIM_email', 'MAIL'),
      AteIdentitySnapshot::variableNames('OIDC_CLAIM_email, HTTP_X_EMAIL, http_mail, REDIRECT_HTTP_X_EMAIL, MAIL'),
      'header-derived names dropped');

    $snap = AteIdentitySnapshot::build($this->coId, $this->sub('snap-http'),
      $this->settings(array('email_env_vars' => 'HTTP_X_EMAIL,OIDC_CLAIM_email')), $this->Req,
      array(
        'HTTP_X_EMAIL' => self::Invited,
        'REDIRECT_HTTP_X_EMAIL' => self::Invited,
        'OIDC_CLAIM_email' => self::Other
      ));

    $this->assertEqual(array(self::Other), $snap['emails'], 'a spoofed header address is not collected');
  }

  /** Verified emails on OrgIdentities carrying the login are collected; unverified are not. */
  public function testCollectsVerifiedLoginEmailsOnly() {
    $sub = $this->sub('snap-verified');
    $this->login($this->p['p1'], $sub, array(self::Third), array(self::Invited));

    $snap = AteIdentitySnapshot::build($this->coId, $sub, $this->settings(), $this->Req,
                                       array('OIDC_CLAIM_email' => self::Other));

    $this->assertEqual(array(self::Other, self::Third), $snap['emails'],
      'claim first, then verified login emails; the unverified invited address is absent');
  }

  /** A failed lookup is recorded as an error, not swallowed. */
  public function testLookupErrorIsRecorded() {
    $Broken = new AteSnapshotBrokenRequest(array(
      'alias' => 'AteEnrollmentRequest',
      'table' => 'ate_enrollment_requests',
      'ds' => 'default'
    ));

    $snap = AteIdentitySnapshot::build($this->coId, $this->sub('snap-err'), $this->settings(), $Broken,
                                       array('OIDC_CLAIM_email' => self::Invited));

    $this->assertEqual(array(self::Invited), $snap['emails'], 'the claim is still read');
    $this->assertEqual(1, count($snap['errors']), 'the failed lookup is an error');

    $eval = $this->Req->evaluateIdentity($this->coId, self::Invited, $snap);
    $this->assertEqual(AteIdentityResultEnum::Mismatch, $eval['result'], 'an error makes it a mismatch');
  }

  /** No login, no snapshot. */
  public function testRequiresIdentifier() {
    $this->assertThrows(function() {
      AteIdentitySnapshot::build($this->coId, '', $this->settings(), $this->Req, array());
    }, 'InvalidArgumentException', 'empty login');
  }

  /** The Registry session name (a PrimaryName array) becomes a display name. */
  public function testNameFromSessionName() {
    $this->assertEqual('Pat Q Researcher', AteIdentitySnapshot::displayName(
      array('given' => 'Pat', 'middle' => 'Q', 'family' => 'Researcher')), 'name parts joined');
    $this->assertEqual('Pat', AteIdentitySnapshot::displayName(' Pat '), 'string trimmed');
    $this->assertNull(AteIdentitySnapshot::displayName(null), 'no name');
  }
}

/** A request model whose verified-email lookup fails. */
class AteSnapshotBrokenRequest extends AteEnrollmentRequest {
  public function verifiedLoginEmails($coId, $identifier) {
    throw new RuntimeException('probe: verified email lookup failed');
  }
}
