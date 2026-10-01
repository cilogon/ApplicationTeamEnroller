<?php
/**
 * COmanage Registry Application Team Enroller Co Petitions Controller
 *
 * The enrollment flow wedge for newcomers (R21, KTD11). The response page
 * saves a newcomer's choices as a draft, binds the session to the
 * invitation and the login (AteResponsesController::SessionNewcomer), and
 * redirects to the start of the CO's newcomer flow. Registry then runs its
 * steps and hands each one to this wedge:
 *
 * - start: runs before any petition exists. Refuses unless the session
 *   binding exists, the current login is the binding's, the flow is the
 *   CO's newcomer flow, the invitation is live, and the draft accepts at
 *   least one application.
 * - petitionerAttributes: runs after core has saved the petitioner's
 *   attributes and created the CoPerson. Repeats start's checks and binds
 *   the petition to the invitation, retiring an earlier unfinished bound
 *   petition, so at most one is in flight.
 * - finalize: runs after core has finalized the petition. Needs the
 *   invitation bound to this petition and the same login; attaches the
 *   login to the new CoPerson and commits the draft through the routing in
 *   KTD7. A petition bound while the invitation was live is honored even if
 *   the invitation has since passed its expiry (KTD14 grace window).
 * - provision: runs after core has provisioned, and sends the researcher to
 *   the confirmation page.
 *
 * A plugin step's exception is only flashed by Registry and does not stop
 * the petition (app/Controller/CoPetitionsController.php dispatch()), so
 * every refusal here is a redirect to the plugin's explanation page. These
 * checks are a front door: a done:<wedge id> parameter skips every plugin
 * step, and the expiry job contains a CoPerson created that way (KTD18).
 *
 * @link          https://github.com/cilogon/ApplicationTeamEnroller
 * @package       registry-plugin
 * @since         COmanage Registry v4.6.0
 */

App::uses('CoPetitionsController', 'Controller');
App::uses('AteResponsesController', 'ApplicationTeamEnroller.Controller');
App::uses('AteInvitation', 'ApplicationTeamEnroller.Model');

class ApplicationTeamEnrollerCoPetitionsController extends CoPetitionsController {
  // Class name, used by Cake
  public $name = "ApplicationTeamEnrollerCoPetitions";

  // CoPetition first: CoPetitionsController works on the modelClass
  public $uses = array(
    'CoPetition',
    'ApplicationTeamEnroller.AteInvitation',
    'ApplicationTeamEnroller.AteEnrollmentRequest',
    'ApplicationTeamEnroller.AteSetting'
  );

  /**
   * Plugin start step: admit only a newcomer the response page handed over.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id       CO Petition ID (none yet at start)
   * @param  Array   $onFinish Redirect target on completion
   */

  protected function execute_plugin_start($id, $onFinish) {
    try {
      list($reason) = $this->liveBinding();
    } catch(Exception $e) {
      $this->refuse('error', 'start', $id, $e->getMessage());
    }

    if($reason !== null) {
      $this->refuse($reason, 'start', $id);
    }

    $this->redirect($onFinish);
  }

  /**
   * Plugin petitionerAttributes step: bind the petition to the invitation.
   * It is the first plugin step every newcomer flow runs with a petition id,
   * since selectEnrollee does not run under the permitted match policies.
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id       CO Petition ID
   * @param  Array   $onFinish Redirect target on completion
   */

  protected function execute_plugin_petitionerAttributes($id, $onFinish) {
    try {
      list($reason, $binding, $inv) = $this->liveBinding();

      if($reason === null) {
        $bound = $this->AteInvitation->bindPetition($binding['co_id'], $inv['id'], $id);

        if(!$bound['bound']) {
          $reason = $this->invitationReason($inv['id'], $binding['co_id']) ?: 'expired';
        } elseif($bound['retired']) {
          $this->log('ApplicationTeamEnroller retired petition ' . $bound['retired'] . ' of invitation '
                     . $inv['id'] . ' in favor of petition ' . (int)$id, LOG_INFO);
        }
      }
    } catch(Exception $e) {
      $this->refuse('error', 'petitionerAttributes', $id, $e->getMessage());
    }

    if($reason !== null) {
      $this->refuse($reason, 'petitionerAttributes', $id);
    }

    $this->redirect($onFinish);
  }

  /**
   * Plugin finalize step: attach the login to the new CoPerson and commit
   * the newcomer's response (R21, KTD5, KTD7, KTD11).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id       CO Petition ID
   * @param  Array   $onFinish Redirect target on completion
   */

  protected function execute_plugin_finalize($id, $onFinish) {
    $reason = null;
    $result = null;
    $coId = null;

    try {
      $args = array();
      $args['conditions']['CoPetition.id'] = $id;
      $args['contain'] = false;

      $pt = $this->CoPetition->find('first', $args);
      $coId = empty($pt['CoPetition']['co_id']) ? null : (int)$pt['CoPetition']['co_id'];

      $args = array();
      $args['conditions']['AteInvitation.co_petition_id'] = $id;
      $args['conditions']['AteInvitation.co_id'] = $coId;
      $args['contain'] = false;

      $inv = $coId ? $this->AteInvitation->find('first', $args) : array();

      $binding = $this->Session->read(AteResponsesController::SessionNewcomer);
      $username = $this->Session->read('Auth.User.username');

      // Not bound (the plugin steps were skipped, KTD18, or this petition
      // was retired in favor of a later one), or bound to another session
      if(empty($inv['AteInvitation']['id'])
         || !$this->sameLogin($binding, $username)
         || (int)$binding['invitation_id'] !== (int)$inv['AteInvitation']['id']
         || (int)$binding['co_id'] !== $coId) {
        $reason = 'newcomer_session';
      } elseif($inv['AteInvitation']['status'] !== AteInvitationStatusEnum::Sent) {
        // No lapse check: a petition bound while live is honored (KTD14)
        $reason = AteInvitation::reasonForStatus($inv['AteInvitation']['status']);
      } elseif($pt['CoPetition']['status'] !== PetitionStatusEnum::Finalized
               || empty($pt['CoPetition']['enrollee_co_person_id'])) {
        $reason = 'newcomer_incomplete';
      }

      $choices = ($reason === null) ? $this->AteEnrollmentRequest->draftChoices($inv['AteInvitation']['id']) : null;

      if($reason === null && $choices === null) {
        $reason = 'newcomer_session';
      }

      if($reason === null) {
        $invId = (int)$inv['AteInvitation']['id'];
        $coPersonId = (int)$pt['CoPetition']['enrollee_co_person_id'];
        $settings = $this->AteSetting->getOrCreateForCo($coId);

        $this->AteEnrollmentRequest->attachLogin($coId, $coPersonId, $username,
                                                 $settings['AteSetting']['login_identifier_type'], $coPersonId);

        $result = $this->AteEnrollmentRequest->commitResponse($coId, $invId, $binding['snapshot'], $choices,
                                                              $coPersonId);

        if(empty($result['handled'])) {
          $reason = $this->invitationReason($invId, $coId) ?: 'answered';
        }
      }
    } catch(Exception $e) {
      $this->refuse(($e->getMessage() === _txt('pl.applicationteamenroller.er.login.ambiguous'))
                    ? 'ambiguous' : 'error', 'finalize', $id, $e->getMessage());
    }

    if($reason !== null) {
      $this->refuse($reason, 'finalize', $id);
    }

    try {
      $this->AteEnrollmentRequest->afterResponse($coId, $result);
    } catch(Exception $e) {
      $this->log('ApplicationTeamEnroller could not announce the response to invitation '
                 . $result['invitation_id'] . ': ' . $e->getMessage());
    }

    // The binding is used up; the confirmation page shows this response
    $this->Session->delete(AteResponsesController::SessionNewcomer);
    $this->Session->delete(AteResponsesController::SessionSnapshot);
    $this->Session->write(AteResponsesController::SessionConfirmation, array(
      'co_id'         => $coId,
      'invitation_id' => (int)$result['invitation_id']
    ));

    $this->redirect($onFinish);
  }

  /**
   * Plugin provision step: after core has provisioned the new CoPerson, show
   * the researcher the confirmation page for the response this petition
   * committed (R19).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $id       CO Petition ID
   * @param  Array   $onFinish Redirect target on completion
   */

  protected function execute_plugin_provision($id, $onFinish) {
    $c = $this->Session->read(AteResponsesController::SessionConfirmation);

    if(!empty($c['invitation_id'])
       && (int)$this->AteInvitation->field('co_petition_id', array('AteInvitation.id' => (int)$c['invitation_id']))
          === (int)$id) {
      $this->redirect(array(
        'plugin'     => 'application_team_enroller',
        'controller' => 'ate_responses',
        'action'     => 'confirmation'
      ));
    }

    $this->redirect($onFinish);
  }

  /**
   * The newcomer binding and its invitation, checked as start and
   * petitionerAttributes require (KTD11): the binding exists and belongs to
   * the current login, this flow is the binding CO's newcomer flow, the
   * invitation is sent and not past its expiry, and the draft accepts at
   * least one application.
   *
   * @since  COmanage Registry v4.6.0
   * @return Array Explanation reason (null if all checks pass), the binding, the AteInvitation fields
   */

  protected function liveBinding() {
    $binding = $this->Session->read(AteResponsesController::SessionNewcomer);

    if(!$this->sameLogin($binding, $this->Session->read('Auth.User.username'))) {
      return array('newcomer_session', $binding, null);
    }

    $coId = (int)$binding['co_id'];
    $settings = $this->AteSetting->getOrCreateForCo($coId);

    if((int)$settings['AteSetting']['newcomer_co_enrollment_flow_id'] !== (int)$this->enrollmentFlowID()) {
      return array('newcomer_session', $binding, null);
    }

    $reason = $this->invitationReason($binding['invitation_id'], $coId);

    if($reason !== null) {
      return array($reason, $binding, null);
    }

    $this->AteInvitation->getDataSource()->flushQueryCache();

    $inv = $this->AteInvitation->find('first', array(
      'conditions' => array('AteInvitation.id' => (int)$binding['invitation_id']),
      'contain'    => false
    ));

    if($inv['AteInvitation']['expires'] < date('Y-m-d H:i:s')) {
      // Past its expiry but in the grace window of an earlier bound
      // petition: that petition is honored, a new one is not started
      return array('expired', $binding, null);
    }

    $choices = $this->AteEnrollmentRequest->draftChoices($inv['AteInvitation']['id']);

    if($choices === null || !in_array(true, $choices, true)) {
      return array('newcomer_session', $binding, null);
    }

    return array(null, $binding, $inv['AteInvitation']);
  }

  /**
   * Whether a newcomer binding exists and belongs to the current login and
   * its snapshot (KTD5).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Array  $binding  SessionNewcomer contents
   * @param  String $username Current Auth.User.username
   * @return Boolean
   */

  protected function sameLogin($binding, $username) {
    return !empty($binding['invitation_id'])
           && !empty($binding['co_id'])
           && is_string($username)
           && $username !== ''
           && isset($binding['identifier'], $binding['snapshot']['identifier'])
           && $username === $binding['identifier']
           && $username === $binding['snapshot']['identifier'];
  }

  /**
   * Why an invitation cannot take a newcomer, after expiring it if it has
   * lapsed (KTD14).
   *
   * @since  COmanage Registry v4.6.0
   * @param  Integer $invitationId AteInvitation ID
   * @param  Integer $coId         CO ID
   * @return String                Explanation reason, or null if it is sent
   */

  protected function invitationReason($invitationId, $coId) {
    $this->AteInvitation->getDataSource()->flushQueryCache();

    $status = $this->AteInvitation->field('status', array(
      'AteInvitation.id'    => (int)$invitationId,
      'AteInvitation.co_id' => (int)$coId
    ));

    if(!$status) {
      return 'unknown';
    }

    if($status === AteInvitationStatusEnum::Sent && $this->AteInvitation->expireIfLapsed($invitationId)) {
      return 'expired';
    }

    return AteInvitation::reasonForStatus($status);
  }

  /**
   * Stop the petition at a plugin step and show the explanation page.
   *
   * @since  COmanage Registry v4.6.0
   * @param  String  $reason Explanation reason
   * @param  String  $step   Step name, for the log
   * @param  Integer $id     CO Petition ID, or null
   * @param  String  $detail Error detail for the log, or null
   */

  protected function refuse($reason, $step, $id, $detail = null) {
    $this->log('ApplicationTeamEnroller refused the newcomer ' . $step . ' step'
               . ($id ? ' of petition ' . (int)$id : '') . ': ' . $reason . ($detail ? ' (' . $detail . ')' : ''));

    $this->redirect(array(
      'plugin'     => 'application_team_enroller',
      'controller' => 'ate_responses',
      'action'     => 'explanation',
      $reason
    ));
  }
}
