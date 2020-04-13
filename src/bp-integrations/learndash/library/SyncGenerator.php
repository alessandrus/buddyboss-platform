<?php
/**
 * BuddyBoss LearnDash integration SyncGenerator class.
 *
 * @package BuddyBoss\LearnDash
 * @since BuddyBoss 1.0.0
 */

namespace Buddyboss\LearndashIntegration\Library;

use BP_Groups_Member;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class for controlling gorup syncing
 *
 * @since BuddyBoss 1.0.0
 */
class SyncGenerator {

	protected $syncingToLearndash  = false;
	protected $syncingToBuddypress = false;
	protected $bpGroupId;
	protected $ldGroupId;
	protected $syncMetaKey = '_sync_group_id';

	/**
	 * Constructor
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function __construct( $bpGroupId = null, $ldGroupId = null ) {
		$this->bpGroupId = $bpGroupId;
		$this->ldGroupId = $ldGroupId;

		$this->populateData();
		$this->verifyInputs();
	}

	/**
	 * Check if there's a ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function hasLdGroup() {
		return ! ! $this->ldGroupId;
	}

	/**
	 * Check if there's a bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function hasBpGroup() {
		return ! ! $this->bpGroupId;
	}

	/**
	 * Get the ld group id
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function getLdGroupId() {
		return $this->ldGroupId;
	}

	/**
	 * Get the bp group id
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function getBpGroupId() {
		return $this->bpGroupId;
	}

	/**
	 * Associate current bp group to a ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function associateToLearndash( $ldGroupId = null ) {
		if ( $this->ldGroupId && ! $ldGroupId ) {
			return $this;
		}

		$this->syncingToLearndash(
			function() use ( $ldGroupId ) {
				$ldGroup = get_post( $ldGroupId );

				if ( ! $ldGroupId || ! $ldGroup ) {
					$this->createLearndashGroup();
				} else {
					$this->unsetBpGroupMeta( false )->unsetLdGroupMeta( false );
					$this->ldGroupId = $ldGroupId;
				}

				$this->setSyncGropuIds();
			}
		);

		return $this;
	}

	/**
	 * Un-associate the current bp group from ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function desyncFromLearndash() {
		if ( ! $this->ldGroupId ) {
			return $this;
		}

		$this->unsetSyncGropuIds();

		return $this;
	}

	/**
	 * delete the bp group without trigging sync
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function deleteBpGroup( $bpGroupId ) {
		$this->syncingToBuddypress(
			function() use ( $bpGroupId ) {
				groups_delete_group( $bpGroupId );
			}
		);
	}

	/**
	 * delete the ld group without trigging sync
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function deleteLdGroup( $ldGroupId ) {
		$this->syncingToLearndash(
			function() use ( $ldGroupId ) {
				wp_delete_post( $ldGroupId, true );
			}
		);
	}

	/**
	 * Associate current ld group to bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function associateToBuddypress( $bpGroupId = null ) {
		if ( $this->bpGroupId && ! $bpGroupId ) {
			return $this;
		}

		$this->syncingToBuddypress(
			function() use ( $bpGroupId ) {
				$bpGroup = groups_get_group( $bpGroupId );

				if ( ! $bpGroupId || ! $bpGroup->id ) {
					$this->createBuddypressGroup();
				} else {
					$this->unsetBpGroupMeta( false )->unsetLdGroupMeta( false );
					$this->bpGroupId = $bpGroupId;
				}

				$this->setSyncGropuIds();
			}
		);

		return $this;
	}

	/**
	 * Un associate current ld group from bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function desyncFromBuddypress() {
		if ( ! $this->bpGroupId ) {
			return $this;
		}

		$this->unsetSyncGropuIds();

		return $this;
	}

	/**
	 * Run a full users sync up bp group to ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function fullSyncToLearndash() {
		 $lastSynced = groups_get_groupmeta( $this->bpGroupId, '_last_sync', true ) ?: 0;

		if ( $lastSynced > $this->getLastSyncTimestamp( 'bp' ) ) {
			return;
		}

		$this->syncBpUsers()->syncBpMods()->syncBpAdmins();
		groups_update_groupmeta( $this->bpGroupId, '_last_sync', time() );
	}

	/**
	 * Run a full users sync up ld group to bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function fullSyncToBuddypress() {
		$lastSynced = groups_get_groupmeta( $this->ldGroupId, '_last_sync', true ) ?: 0;

		if ( $lastSynced > $this->getLastSyncTimestamp( 'ld' ) ) {
			return;
		}

		$this->syncLdAdmins()->syncLdUsers();
		update_post_meta( $this->ldGroupId, '_last_sync', time() );
	}

	/**
	 * Sync the bp admins to ld
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncBpAdmins() {
		$this->syncingToLearndash(
			function() {
				$adminIds = groups_get_group_admins( $this->bpGroupId );

				foreach ( $adminIds as $admin ) {
					$this->syncBpAdmin( $admin->user_id, false, false );
				}
			}
		);

		$this->clearLdGroupCache();

		return $this;
	}

	/**
	 * Sync the bp mods to ld
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncBpMods() {
		$this->syncingToLearndash(
			function() {
				$modIds = groups_get_group_mods( $this->bpGroupId );

				foreach ( $modIds as $mod ) {
					$this->syncBpMod( $mod->user_id, false, false );
				}
			}
		);

		$this->clearLdGroupCache();

		return $this;
	}

	/**
	 * Sync the bp members to ld
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncBpUsers() {
		$this->syncingToLearndash(
			function() {
				$members = groups_get_group_members(
					array(
						'group_id' => $this->bpGroupId,
					)
				);

				$members = $members['members'];

				foreach ( $members as $member ) {
					$this->syncBpMember( $member->ID, false, false );
				}
			}
		);

		$this->clearLdGroupCache();

		return $this;
	}

	/**
	 * Sync the ld admins to bp
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncLdAdmins() {
		$this->syncingToBuddypress(
			function() {
				$adminIds = learndash_get_groups_administrator_ids( $this->ldGroupId );

				foreach ( $adminIds as $adminId ) {
					$this->syncLdAdmin( $adminId );
				}
			}
		);

		return $this;
	}

	/**
	 * Sync the ld students to bp
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncLdUsers() {
		$this->syncingToBuddypress(
			function() {
				$userIds = learndash_get_groups_user_ids( $this->ldGroupId );

				foreach ( $userIds as $userId ) {
					$this->syncLdUser( $userId );
				}
			}
		);

		return $this;
	}

	/**
	 * Sync a bp admin to ld
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncBpAdmin( $userId, $remove = false, $clearCache = true ) {
		$this->syncingToLearndash(
			function() use ( $userId, $remove ) {
				call_user_func_array( $this->getBpSyncFunction( 'admin' ), array( $userId, $this->ldGroupId, $remove ) );
				$this->maybeRemoveAsLdUser( 'admin', $userId );
			}
		);

		if ( $clearCache ) {
			$this->clearLdGroupCache();
		}

		return $this;
	}

	/**
	 * Sync a bp mod to ld
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncBpMod( $userId, $remove = false, $clearCache = true ) {
		$this->syncingToLearndash(
			function() use ( $userId, $remove ) {
				call_user_func_array( $this->getBpSyncFunction( 'mod' ), array( $userId, $this->ldGroupId, $remove ) );
				$this->maybeRemoveAsLdUser( 'mod', $userId );
			}
		);

		if ( $clearCache ) {
			$this->clearLdGroupCache();
		}

		return $this;
	}

	/**
	 * Sync a bp member to ld
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncBpMember( $userId, $remove = false, $clearCache = true ) {
		$this->syncingToLearndash(
			function() use ( $userId, $remove ) {
				call_user_func_array( $this->getBpSyncFunction( 'user' ), array( $userId, $this->ldGroupId, $remove ) );

				// if sync to user, we need to remove previous admin
				if ( 'user' == $this->getBpSyncToRole( 'user' ) ) {
					call_user_func_array( 'ld_update_leader_group_access', array( $userId, $this->ldGroupId, true ) );
				}
			}
		);

		if ( $clearCache ) {
			$this->clearLdGroupCache();
		}

		return $this;
	}

	/**
	 * Sync a ld admin to bp
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncLdAdmin( $userId, $remove = false ) {
		$this->syncingToBuddypress(
			function() use ( $userId, $remove ) {
				$this->addUserToBpGroup( $userId, 'admin', $remove );
			}
		);

		return $this;
	}

	/**
	 * Sync a ld student to bp
	 *
	 * @since BuddyBoss 1.0.0
	 */
	public function syncLdUser( $userId, $remove = false ) {

		if ( ! isset( $this->ldGroupId ) ) {
			return $this;
		}

		$ldGroupAdmins = learndash_get_groups_administrator_ids( $this->ldGroupId );

		// if this user is learndash leader, we don't want to downgrad them (bp only allow 1 user)
		if ( in_array( $userId, $ldGroupAdmins ) ) {
			return $this;
		}

		$this->syncingToBuddypress(
			function() use ( $userId, $remove ) {
				$this->addUserToBpGroup( $userId, 'user', $remove );
			}
		);

		return $this;
	}

	/**
	 * Verify the givent group ids still exists in db
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function verifyInputs() {
		if ( $this->bpGroupId && ! groups_get_group( $this->bpGroupId )->id ) {
			$this->unsetBpGroupMeta();
		}

		if ( $this->ldGroupId && ! get_post( $this->ldGroupId ) ) {
			$this->unsetLdGroupMeta();
		}
	}

	/**
	 * Populate the class data based on given input
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function populateData() {
		if ( ! $this->bpGroupId ) {
			$this->bpGroupId = $this->loadBpGroupId();
		}

		if ( ! $this->ldGroupId ) {
			$this->ldGroupId = $this->loadLdGroupId();
		}
	}

	/**
	 * Find the bp group id on current ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function loadBpGroupId() {
		return get_post_meta( $this->ldGroupId, $this->syncMetaKey, true ) ?: null;
	}

	/**
	 * Find the ld group id on current bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function loadLdGroupId() {
		return groups_get_groupmeta( $this->bpGroupId, $this->syncMetaKey, true ) ?: null;
	}

	/**
	 * Sasve bp group id to current ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function setBpGroupId() {
		 update_post_meta( $this->ldGroupId, $this->syncMetaKey, $this->bpGroupId );
		return $this;
	}

	/**
	 * Sasve ld group id to current bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function setLdGroupId() {
		 groups_update_groupmeta( $this->bpGroupId, $this->syncMetaKey, $this->ldGroupId );
		return $this;
	}

	/**
	 * Force id sync
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function setSyncGropuIds() {
		return $this->setLdGroupId()->setBpGroupId();
	}

	/**
	 * Remove bp group id from current ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function unsetBpGroupMeta( $removeProp = true ) {
		if ( $removeProp ) {
			$this->bpGroupId = null;
		}

		delete_post_meta( $this->ldGroupId, $this->syncMetaKey );
		return $this;
	}

	/**
	 * Remove ld group id from current bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function unsetLdGroupMeta( $removeProp = true ) {
		if ( $removeProp ) {
			$this->ldGroupId = null;
		}

		groups_delete_groupmeta( $this->bpGroupId, $this->syncMetaKey );
		return $this;
	}

	/**
	 * Force unsync group ids
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function unsetSyncGropuIds() {
		$this->unsetBpGroupMeta( false )->unsetLdGroupMeta( false );
		$this->bpGroupId = $this->ldGroupId = null;
		return $this;
	}

	/**
	 * Greate a ld group based on current bp group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function createLearndashGroup() {
		 $bpGroup = groups_get_group( $this->bpGroupId );

		$this->ldGroupId = wp_insert_post(
			array(
				'post_title'   => $bpGroup->name,
				'post_author'  => $bpGroup->creator_id,
				'post_content' => $bpGroup->description,
				'post_status'  => 'publish',
				'post_type'    => learndash_get_post_type_slug( 'group' ),
			)
		);
	}

	/**
	 * Create bp group based on current ld group
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function createBuddypressGroup() {
		$ldGroup  = get_post( $this->ldGroupId );
		$settings = bp_ld_sync( 'settings' );

		$this->bpGroupId = groups_create_group(
			array(
				'name'   => $ldGroup->post_title ?: "For Social Group: {$this->ldGroupId}",
				'status' => $settings->get( 'learndash.default_bp_privacy' ),
			)
		);

		groups_update_groupmeta( $this->bpGroupId, 'invite_status', $settings->get( 'learndash.default_bp_invite_status' ) );

		$this->setSyncGropuIds();
	}

	/**
	 * Maybe remove ld user if user is promote or demote from bp
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function maybeRemoveAsLdUser( $type, $userId ) {
		if ( 'user' == $this->getBpSyncToRole( $type ) ) {
			return;
		}

		// remove them as user, cause they are leader now
		ld_update_group_access( $userId, $this->ldGroupId, true );
	}

	/**
	 * Get the bp role to sync to
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function getBpSyncToRole( $type ) {
		return bp_ld_sync( 'settings' )->get( "buddypress.default_{$type}_sync_to" );
	}

	/**
	 * Get the function that update ld group role
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function getBpSyncFunction( $type ) {
		switch ( $this->getBpSyncToRole( $type ) ) {
			case 'admin':
				return 'ld_update_leader_group_access';
			default:
				return 'ld_update_group_access';
		}
	}

	/**
	 * Get the ld role to sync to
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function getLdSyncToRole( $type ) {
		return bp_ld_sync( 'settings' )->get( "learndash.default_{$type}_sync_to" );
	}

	/**
	 * Add a user to bp group by role
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function addUserToBpGroup( $userId, $type, $remove ) {
		global $wpdb, $bp, $messages_template;

		$groupMember = new BP_Groups_Member( $userId, $this->bpGroupId );
		$syncTo      = $this->getLdSyncToRole( $type );

		if ( $remove ) {

			$group_thread = (int) groups_get_groupmeta( (int) $this->bpGroupId, 'group_message_thread' );

			if ( $group_thread > 0 ) {
				$first_message     = \BP_Messages_Thread::get_first_message( $group_thread );
				$message_users_ids = bp_messages_get_meta( $first_message->id, 'message_users_ids', true ); // users list

				$message_users_ids = explode( ',', $message_users_ids );
				$group_name        = bp_get_group_name( groups_get_group( (int) $this->bpGroupId ) );
				$text              = sprintf( __( 'Left "%s" ', 'buddyboss' ), $group_name );
				if ( ( $key = array_search( $userId, $message_users_ids ) ) !== false ) {
					unset( $message_users_ids[ $key ] );
				}

				bp_messages_update_meta( $first_message->id, 'message_users_ids', implode( ',', $message_users_ids ) );

				remove_action( 'messages_message_sent', 'messages_notification_new_message', 10 );
				$new_reply = messages_new_message( array(
					'sender_id'  => $userId,
					'thread_id'  => $group_thread,
					'subject'    => '',
					'content'    => '<p> </p>',
					'date_sent'  => $date_sent = bp_core_current_time(),
					'error_type' => 'wp_error',
				) );
				add_action( 'messages_message_sent', 'messages_notification_new_message', 10 );

				if ( ! is_wp_error( $new_reply ) && true === is_int( ( int ) $new_reply ) ) {
					if ( bp_has_message_threads( array( 'include' => $new_reply ) ) ) {
						while ( bp_message_threads() ) {
							bp_message_thread();
							$last_message_id = (int) $messages_template->thread->last_message_id;
							bp_messages_update_meta( $last_message_id, 'group_message_group_left', 'yes' );
							bp_messages_update_meta( $last_message_id, 'group_id', (int) $this->bpGroupId );
						}
					}
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$bp->messages->table_name_recipients} WHERE user_id = %d AND thread_id = %d", $user_id, (int) $group_thread ) );

			}
			return $groupMember->remove();
		}

		$groupMember->group_id      = $this->bpGroupId;
		$groupMember->user_id       = $userId;
		$groupMember->is_admin      = 0;
		$groupMember->is_mod        = 0;
		$groupMember->is_confirmed  = 1;
		$groupMember->date_modified = bp_core_current_time();

		if ( 'user' !== $syncTo ) {
			$var               = "is_{$syncTo}";
			$groupMember->$var = 1;
		}

		$groupMember->save();

		// Add Member to group messages thread.
		if ( true === bp_disable_group_messages() && bp_is_active( 'messages' ) ) {

			$group_thread = (int) groups_get_groupmeta( (int) $groupMember->group_id, 'group_message_thread' );

			$is_active_recipient = \BP_Messages_Thread::is_thread_recipient( (int) $group_thread, (int) $userId );

			if ( $group_thread > 0 && false === $is_active_recipient ) {

				$first_message = \BP_Messages_Thread::get_first_message( $group_thread );

				$message_users_ids = bp_messages_get_meta( $first_message->id, 'message_users_ids', true ); // users list
				$message_users_ids = explode( ',', $message_users_ids );
				array_push( $message_users_ids, $user_id );
				$group_name = bp_get_group_name( groups_get_group( $groupMember->group_id ) );
				$text       = sprintf( __( 'Joined "%s" ', 'buddyboss' ), $group_name );

				bp_messages_update_meta( $first_message->id, 'message_users_ids', implode( ',', $message_users_ids ) );

				$wpdb->query( $wpdb->prepare( "INSERT INTO {$bp->messages->table_name_recipients} ( user_id, thread_id, unread_count ) VALUES ( %d, %d, 0 )", $userId, $group_thread ) );

				remove_action( 'messages_message_sent', 'messages_notification_new_message', 10 );
				$new_reply = messages_new_message( array(
					'thread_id'  => $group_thread,
					'sender_id'  => $userId,
					'subject'    => '',
					'content'    => '<p> </p>',
					'date_sent'  => $date_sent = bp_core_current_time(),
					'error_type' => 'wp_error',
				) );
				add_action( 'messages_message_sent', 'messages_notification_new_message', 10 );
				if ( ! is_wp_error( $new_reply ) && true === is_int( ( int ) $new_reply ) ) {
					if ( bp_has_message_threads( array( 'include' => $new_reply ) ) ) {
						while ( bp_message_threads() ) {
							bp_message_thread();
							$last_message_id = (int) $messages_template->thread->last_message_id;
							bp_messages_update_meta( $last_message_id, 'group_message_group_joined', 'yes' );
							bp_messages_update_meta( $last_message_id, 'group_id', $groupMember->group_id );
						}
					}
				}
			}

		}
	}

	/**
	 * Clear the ld cache after sync
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function clearLdGroupCache() {
		delete_transient( "learndash_group_leaders_{$this->ldGroupId}" );
		delete_transient( "learndash_group_users_{$this->ldGroupId}" );
	}

	/**
	 * Wrapper to prevent infinite 2 way sync when syncing to learndash
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function syncingToLearndash( $callback ) {
		global $bp_ld_sync__syncing_to_learndash;

		$bp_ld_sync__syncing_to_learndash = true;
		$callback();
		$bp_ld_sync__syncing_to_learndash = false;

		return $this;
	}

	/**
	 * Wrapper to prevent infinite 2 way sync when syncing to buddypress
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function syncingToBuddypress( $callback ) {
		global $bp_ld_sync__syncing_to_buddypress;

		$bp_ld_sync__syncing_to_buddypress = true;
		$callback();
		$bp_ld_sync__syncing_to_buddypress = false;

		return $this;
	}

	/**
	 * Get the timestamp when the group is last synced
	 *
	 * @since BuddyBoss 1.0.0
	 */
	protected function getLastSyncTimestamp( $type = 'bp' ) {
		if ( ! $lastSync = bp_get_option( "bp_ld_sync/{$type}_last_synced" ) ) {
			$lastSync = time();
			bp_update_option( "bp_ld_sync/{$type}_last_synced", $lastSync );
		}

		return $lastSync;
	}
}
