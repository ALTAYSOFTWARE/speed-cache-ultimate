<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class DbOptimizer {

    public static function run() {
        global $wpdb;
        $results = array();

        // 1. Post revisions
        $rev = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        if ( $rev ) {
            $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
            $results['revisions'] = (int) $rev;
        } else {
            $results['revisions'] = 0;
        }

        // 2. Auto drafts
        $ad = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
        if ( $ad ) {
            $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
            $results['auto_drafts'] = (int) $ad;
        } else {
            $results['auto_drafts'] = 0;
        }

        // 3. Trashed posts
        $tp = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        if ( $tp ) {
            $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
            $results['trashed_posts'] = (int) $tp;
        } else {
            $results['trashed_posts'] = 0;
        }

        // 4. Spam comments
        $sc = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
        if ( $sc ) {
            $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
            $results['spam_comments'] = (int) $sc;
        } else {
            $results['spam_comments'] = 0;
        }

        // 5. Trashed comments
        $tc = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
        if ( $tc ) {
            $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
            $results['trashed_comments'] = (int) $tc;
        } else {
            $results['trashed_comments'] = 0;
        }

        // 6. Expired transients
        $time = time();
        $expired = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like( '_transient_timeout_' ) . '%',
            $time
        ) );
        $et = 0;
        if ( $expired ) {
            foreach ( $expired as $row ) {
                $transient = str_replace( '_transient_timeout_', '', $row->option_name );
                delete_transient( $transient );
                $et++;
            }
        }
        $results['expired_transients'] = $et;

        // 7. Orphaned postmeta
        $opm = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
        if ( $opm ) {
            $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
            $results['orphan_postmeta'] = (int) $opm;
        } else {
            $results['orphan_postmeta'] = 0;
        }

        // 8. Orphaned commentmeta
        $ocm = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
        if ( $ocm ) {
            $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
            $results['orphan_commentmeta'] = (int) $ocm;
        } else {
            $results['orphan_commentmeta'] = 0;
        }

        // 9. Orphaned usermeta
        $oum = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
        if ( $oum ) {
            $wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
            $results['orphan_usermeta'] = (int) $oum;
        } else {
            $results['orphan_usermeta'] = 0;
        }

        // 10. Optimize tables
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        foreach ( $tables as $table ) {
            $wpdb->query( "OPTIMIZE TABLE {$table[0]}" );
        }
        $results['tables_optimized'] = count( $tables );

        update_option( 'wcu_db_last_run', time() );
        return $results;
    }
}
