<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class DbOptimizer {
    public static function run( $tasks = array() ) {
        global $wpdb;
        $results = array();
        $tasks   = ! empty( $tasks ) ? $tasks : array(
            'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments',
            'trashed_comments', 'expired_transients', 'orphan_postmeta', 'optimize_tables',
        );

        if ( in_array( 'revisions', $tasks, true ) ) {
            $results['revisions'] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        }
        if ( in_array( 'auto_drafts', $tasks, true ) ) {
            $results['auto_drafts'] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
        }
        if ( in_array( 'trashed_posts', $tasks, true ) ) {
            $results['trashed_posts'] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        }
        if ( in_array( 'spam_comments', $tasks, true ) ) {
            $results['spam_comments'] = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
        }
        if ( in_array( 'trashed_comments', $tasks, true ) ) {
            $results['trashed_comments'] = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
        }
        if ( in_array( 'expired_transients', $tasks, true ) ) {
            $results['expired_transients'] = self::delete_expired_transients();
        }
        if ( in_array( 'orphan_postmeta', $tasks, true ) ) {
            $results['orphan_postmeta'] = (int) $wpdb->query(
                "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
            );
        }
        if ( in_array( 'optimize_tables', $tasks, true ) ) {
            $results['optimize_tables'] = self::optimize_tables();
        }

        update_option( 'wcu_db_last_run', time(), false );
        Logger::log( 'db_optimize', 'run', wp_json_encode( $results ) );
        return $results;
    }

    protected static function delete_expired_transients() {
        global $wpdb;
        $now   = time();
        $count = 0;
        $rows  = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%'" );
        foreach ( $rows as $row ) {
            if ( (int) $row->option_value < $now ) {
                $key = str_replace( '_transient_timeout_', '', $row->option_name );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name IN (%s,%s)",
                    '_transient_' . $key,
                    '_transient_timeout_' . $key
                ) );
                $count++;
            }
        }
        return $count;
    }

    protected static function optimize_tables() {
        global $wpdb;
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        foreach ( $tables as $t ) {
            $wpdb->query( "OPTIMIZE TABLE `{$t}`" );
        }
        return count( $tables );
    }
}
