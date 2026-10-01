<?php
/**
 * U7: identity evaluation (R20, R22, KTD5, KTD6). Given a stored identity
 * snapshot, the invited address, and the CO, the evaluation returns match,
 * mismatch, or link_required, and never needs the request environment.
 *
 * Each fixture pairs addresses and owners so that a rule's absence changes
 * the answer: a test of the "belongs to another person" rule has the login
 * report the invited address, so only that rule can make it a mismatch.
 */

class MismatchTest extends AteEngineTestCase {

  /**
   * The login reports the invited address, in different case, and is linked
   * to P1, who owns no conflicting address: a match, responder P1.
   */
  public function testMatchIsCaseInsensitive() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array('Pat@Uni.Example'));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited,
                                      $this->snapshot($sub, array('Pat@UNI.example')));

    $this->assertEqual(AteIdentityResultEnum::Match, $r['result']);
    $this->assertFalse($r['mismatch']);
    $this->assertEqual($this->p['p1'], $r['responder_co_person_id']);
    $this->assertNull($r['link_target_co_person_id']);
  }

  /**
   * Covers AE7 (evaluation). The login reports only another address, and the
   * invited address belongs to no one: a mismatch.
   */
  public function testOnlyAnotherAddressIsMismatch() {
    $sub = $this->sub('p3');
    $this->login($this->p['p3'], $sub, array(self::Other));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array(self::Other)));

    $this->assertEqual(AteIdentityResultEnum::Mismatch, $r['result']);
    $this->assertTrue($r['mismatch']);
    $this->assertTrue(in_array('email_not_reported', $r['reasons'], true), var_export($r['reasons'], true));
    $this->assertEqual($this->p['p3'], $r['responder_co_person_id']);
  }

  /** A snapshot with no emails is a mismatch (R22). */
  public function testNoEmailsIsMismatch() {
    $sub = $this->sub('p3');
    $this->login($this->p['p3'], $sub);

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array()));

    $this->assertEqual(AteIdentityResultEnum::Mismatch, $r['result']);
    $this->assertTrue($r['mismatch']);
    $this->assertTrue(in_array('no_emails', $r['reasons'], true), var_export($r['reasons'], true));
  }

  /**
   * A snapshot whose email lookup failed is a mismatch even though it
   * reports the invited address, and the error is logged (KTD5).
   */
  public function testSnapshotLookupErrorIsMismatchAndLogged() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array(self::Invited),
      array('errors' => array('verified email lookup failed: boom-4711'))));

    $this->assertEqual(AteIdentityResultEnum::Mismatch, $r['result']);
    $this->assertTrue($r['mismatch']);
    $this->assertTrue(in_array('lookup_error', $r['reasons'], true), var_export($r['reasons'], true));
    $this->assertContains('boom-4711', implode("\n", AteRecordingLog::$lines), 'the error must be logged');
  }

  /**
   * A lookup that throws during evaluation is a mismatch, is logged, and
   * does not escape. The login reports the invited address, so only the
   * error can make it a mismatch.
   */
  public function testThrownLookupIsMismatchAndLogged() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $this->Req->failLookup = true;

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array(self::Invited)));

    $this->assertEqual(AteIdentityResultEnum::Mismatch, $r['result']);
    $this->assertTrue(in_array('lookup_error', $r['reasons'], true), var_export($r['reasons'], true));
    $this->assertContains('probe: login lookup failed', implode("\n", AteRecordingLog::$lines));
  }

  /**
   * Covers AE9. The invited address is verified on P1; the login is linked
   * to P2 and even reports the invited address. Only the owner rule makes
   * this a mismatch.
   */
  public function testInvitedAddressOnAnotherPersonIsMismatch() {
    $this->login($this->p['p1'], $this->sub('p1'), array(self::Invited));
    $sub2 = $this->sub('p2');
    $this->login($this->p['p2'], $sub2, array(self::Third));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited,
                                      $this->snapshot($sub2, array(self::Invited, self::Third)));

    $this->assertEqual(AteIdentityResultEnum::Mismatch, $r['result']);
    $this->assertTrue($r['mismatch']);
    $this->assertTrue(in_array('address_on_other_person', $r['reasons'], true), var_export($r['reasons'], true));
    $this->assertEqual($this->p['p2'], $r['responder_co_person_id']);
    $this->assertNull($r['link_target_co_person_id'], 'a mismatch never links');
  }

  /**
   * Covers AE16 (evaluation). The invited address is verified on an
   * OrgIdentity linked to P1; the login is linked to no one and reports the
   * invited address. Without the link rule this would be a match.
   */
  public function testInvitedAddressOnPersonAndUnlinkedLoginIsLinkRequired() {
    $this->login($this->p['p1'], $this->sub('p1'), array(self::Invited));
    $sub = $this->sub('new');
    $this->login(null, $sub, array(self::Invited));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array(self::Invited)));

    $this->assertEqual(AteIdentityResultEnum::LinkRequired, $r['result']);
    $this->assertEqual($this->p['p1'], $r['link_target_co_person_id']);
    $this->assertNull($r['responder_co_person_id']);
    $this->assertFalse($r['mismatch'], 'the login reported the invited address');
  }

  /**
   * The invited address as a verified EmailAddress on the CoPerson record
   * itself counts as belonging to that person.
   */
  public function testAddressOnCoPersonRecordCountsForLink() {
    $this->fx->emailAddress(self::Invited, array('co_person_id' => $this->p['p2'], 'verified' => true));
    $sub = $this->sub('new');

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array(self::Other)));

    $this->assertEqual(AteIdentityResultEnum::LinkRequired, $r['result']);
    $this->assertEqual($this->p['p2'], $r['link_target_co_person_id']);
    $this->assertTrue($r['mismatch'], 'the login did not report the invited address');
  }

  /**
   * Unverified rows never count. P1 has the invited address only as an
   * unverified row, so an unlinked login reporting it is a plain match
   * (a newcomer), not link_required; and a login whose only copy of the
   * invited address is unverified does not collect it.
   */
  public function testUnverifiedEmailDoesNotCount() {
    $this->login($this->p['p1'], $this->sub('p1'), array(self::Third), array(self::Invited));
    $this->fx->emailAddress(self::Invited, array('co_person_id' => $this->p['p1'], 'verified' => false));

    $subNew = $this->sub('new');
    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($subNew, array(self::Invited)));
    $this->assertEqual(AteIdentityResultEnum::Match, $r['result'], 'an unverified row makes no one an owner');
    $this->assertNull($r['link_target_co_person_id']);

    $sub3 = $this->sub('p3');
    $this->login($this->p['p3'], $sub3, array(self::Other), array(self::Invited));
    $emails = $this->Req->verifiedLoginEmails($this->coId, $sub3);
    $this->assertEqual(array(self::Other), $emails, 'only verified rows are collected');

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub3, $emails));
    $this->assertEqual(AteIdentityResultEnum::Mismatch, $r['result']);
  }

  /**
   * verifiedLoginEmails() collects verified rows only from OrgIdentities in
   * the CO carrying the login as an Active login identifier.
   */
  public function testVerifiedLoginEmailsScope() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    // Same identifier, but not a login identifier: not collected
    $this->login(null, $sub, array('notlogin@x.example'), array(), array('login' => false));
    // Same identifier, suspended: not collected
    $this->login(null, $sub, array('suspended@x.example'), array(), array('status' => 'S'));
    // Same identifier in another CO: not collected
    $this->login(null, $sub, array('otherco@x.example'), array(), array(), $this->otherCoId);
    // A different login: not collected
    $this->login(null, $this->sub('someone'), array(self::Third));

    $this->assertEqual(array(self::Invited), $this->Req->verifiedLoginEmails($this->coId, $sub));
  }

  /**
   * KTD6: an existing member is a CoPerson of this CO with an OrgIdentity
   * carrying the login as an Active login identifier, whatever the
   * CoPerson's status.
   */
  public function testExistingMemberLookup() {
    $sub1 = $this->sub('p1');
    $this->login($this->p['p1'], $sub1);
    $this->assertEqual($this->p['p1'], $this->Req->existingMemberCoPersonId($this->coId, $sub1));

    $subNotLogin = $this->sub('p2');
    $this->login($this->p['p2'], $subNotLogin, array(), array(), array('login' => false));
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $subNotLogin), 'login=false');

    $subSuspended = $this->sub('p3');
    $this->login($this->p['p3'], $subSuspended, array(), array(), array('status' => 'S'));
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $subSuspended), 'suspended identifier');

    $subOther = $this->sub('other');
    $this->login($this->p['otherCo'], $subOther, array(), array(), array(), $this->otherCoId);
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $subOther), 'another CO');

    $subUnlinked = $this->sub('unlinked');
    $this->login(null, $subUnlinked);
    $this->assertNull($this->Req->existingMemberCoPersonId($this->coId, $subUnlinked), 'unlinked');

    $subSuspendedPerson = $this->sub('sp');
    $sp = $this->fx->person($this->coId, array('status' => 'S'));
    $this->login($sp, $subSuspendedPerson);
    $this->assertEqual($sp, $this->Req->existingMemberCoPersonId($this->coId, $subSuspendedPerson),
      'any CoPerson status counts');
  }

  /**
   * The invited address verified on the responder's own CoPerson is not a
   * conflict: a match. Guards against flagging every owned address.
   */
  public function testAddressOnResponderIsMatch() {
    $sub = $this->sub('p1');
    $this->login($this->p['p1'], $sub, array(self::Invited));
    $this->fx->emailAddress(self::Invited, array('co_person_id' => $this->p['p1'], 'verified' => true));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot($sub, array(self::Invited)));

    $this->assertEqual(AteIdentityResultEnum::Match, $r['result']);
    $this->assertEqual(array(), $r['reasons']);
  }

  /**
   * The invited address on two CoPeople and an unlinked login: approval
   * would need one target, so the result is link_required with no target,
   * never a guess.
   */
  public function testAmbiguousOwnersLinkRequiredWithoutTarget() {
    $this->fx->emailAddress(self::Invited, array('co_person_id' => $this->p['p1'], 'verified' => true));
    $this->fx->emailAddress(self::Invited, array('co_person_id' => $this->p['p2'], 'verified' => true));

    $r = $this->Req->evaluateIdentity($this->coId, self::Invited,
                                      $this->snapshot($this->sub('new'), array(self::Invited)));

    $this->assertEqual(AteIdentityResultEnum::LinkRequired, $r['result']);
    $this->assertNull($r['link_target_co_person_id']);
    $this->assertTrue(in_array('ambiguous_owner', $r['reasons'], true), var_export($r['reasons'], true));
  }

  /** A snapshot without an identifier is refused. */
  public function testSnapshotWithoutIdentifierIsRefused() {
    $this->assertThrows(function() {
      $this->Req->evaluateIdentity($this->coId, self::Invited, $this->snapshot('', array(self::Invited)));
    }, 'InvalidArgumentException', 'empty identifier');
  }
}
