<?php
/**
 * Notification Manager Utility Class
 *
 * Provides a toast notification system for user feedback.
 *
 * @package    FreshRank_AI
 * @subpackage FreshRank_AI/includes/utilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FreshRank Notification Manager
 *
 * Singleton class for managing toast notifications with consistent
 * styling and behavior across the plugin.
 */
class FreshRank_Notification_Manager {

	/**
	 * Singleton instance
	 *
	 * @var FreshRank_Notification_Manager|null
	 */
	private static $instance = null;

	/**
	 * Queued notifications
	 *
	 * @var array
	 */
	private $notifications = array();

	/**
	 * Get singleton instance
	 *
	 * @return FreshRank_Notification_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_footer', array( $this, 'render_notifications' ) );
	}

	/**
	 * Enqueue notification scripts and styles
	 */
	public function enqueue_scripts() {
		// Check if we're on a FreshRank admin page
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'freshrank' ) === false ) {
			return;
		}

		// Inline CSS for notifications
		wp_add_inline_style(
			'freshrank-admin-css',
			'
            .freshrank-notification-container {
                position: fixed;
                top: 32px;
                right: 20px;
                z-index: 999999;
                max-width: 400px;
            }

            .freshrank-notification {
                background: #fff;
                border-left: 4px solid #2271b1;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                border-radius: 4px;
                padding: 12px 40px 12px 12px;
                margin-bottom: 10px;
                position: relative;
                animation: freshrank-slide-in 0.3s ease-out;
            }

            .freshrank-notification.success {
                border-left-color: #00a32a;
            }

            .freshrank-notification.error {
                border-left-color: #d63638;
            }

            .freshrank-notification.warning {
                border-left-color: #dba617;
            }

            .freshrank-notification.info {
                border-left-color: #2271b1;
            }

            .freshrank-notification-icon {
                display: inline-block;
                width: 20px;
                height: 20px;
                margin-right: 8px;
                vertical-align: middle;
            }

            .freshrank-notification-message {
                display: inline-block;
                vertical-align: middle;
                font-size: 13px;
                color: #2c3338;
            }

            .freshrank-notification-close {
                position: absolute;
                top: 8px;
                right: 8px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 4px;
                color: #646970;
                font-size: 16px;
                line-height: 1;
            }

            .freshrank-notification-close:hover {
                color: #2c3338;
            }

            @keyframes freshrank-slide-in {
                from {
                    transform: translateX(400px);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            @keyframes freshrank-slide-out {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(400px);
                    opacity: 0;
                }
            }

            .freshrank-notification.hiding {
                animation: freshrank-slide-out 0.3s ease-out forwards;
            }
        '
		);

		// Inline JavaScript for notifications
		wp_add_inline_script(
			'freshrank-admin-js',
			'
            (function($) {
                window.FreshRankNotifications = {
                    container: null,

                    init: function() {
                        if (!this.container) {
                            this.container = $("<div class=\"freshrank-notification-container\"></div>");
                            $("body").append(this.container);
                        }
                    },

                    show: function(type, message, duration) {
                        this.init();

                        var icons = {
                            success: "dashicons-yes-alt",
                            error: "dashicons-dismiss",
                            warning: "dashicons-warning",
                            info: "dashicons-info"
                        };

                        var icon = icons[type] || icons.info;
                        duration = duration || 3000;

                        var notification = $("<div class=\"freshrank-notification " + type + "\"></div>");
                        notification.html(
                            "<span class=\"dashicons " + icon + " freshrank-notification-icon\"></span>" +
                            "<span class=\"freshrank-notification-message\">" + message + "</span>" +
                            "<button class=\"freshrank-notification-close\" aria-label=\"Close\">&times;</button>"
                        );

                        this.container.append(notification);

                        var self = this;
                        notification.find(".freshrank-notification-close").on("click", function() {
                            self.hide(notification);
                        });

                        if (duration > 0) {
                            setTimeout(function() {
                                self.hide(notification);
                            }, duration);
                        }
                    },

                    hide: function(notification) {
                        notification.addClass("hiding");
                        setTimeout(function() {
                            notification.remove();
                        }, 300);
                    },

                    success: function(message, duration) {
                        this.show("success", message, duration || 3000);
                    },

                    error: function(message, duration) {
                        this.show("error", message, duration || 5000);
                    },

                    warning: function(message, duration) {
                        this.show("warning", message, duration || 4000);
                    },

                    info: function(message, duration) {
                        this.show("info", message, duration || 3000);
                    }
                };
            })(jQuery);
        '
		);
	}

	/**
	 * Add success notification
	 *
	 * @param string $message  Notification message
	 * @param int    $duration Duration in milliseconds (default: 3000)
	 *
	 * @example
	 * FreshRank_Notification_Manager::success('Analysis completed successfully');
	 */
	public static function success( $message, $duration = 3000 ) {
		self::get_instance()->add_notification( 'success', $message, $duration );
	}

	/**
	 * Add error notification
	 *
	 * @param string $message  Notification message
	 * @param int    $duration Duration in milliseconds (default: 5000)
	 *
	 * @example
	 * FreshRank_Notification_Manager::error('Failed to connect to API');
	 */
	public static function error( $message, $duration = 5000 ) {
		self::get_instance()->add_notification( 'error', $message, $duration );
	}

	/**
	 * Add warning notification
	 *
	 * @param string $message  Notification message
	 * @param int    $duration Duration in milliseconds (default: 4000)
	 *
	 * @example
	 * FreshRank_Notification_Manager::warning('API key is missing');
	 */
	public static function warning( $message, $duration = 4000 ) {
		self::get_instance()->add_notification( 'warning', $message, $duration );
	}

	/**
	 * Add info notification
	 *
	 * @param string $message  Notification message
	 * @param int    $duration Duration in milliseconds (default: 3000)
	 *
	 * @example
	 * FreshRank_Notification_Manager::info('Processing in background...');
	 */
	public static function info( $message, $duration = 3000 ) {
		self::get_instance()->add_notification( 'info', $message, $duration );
	}

	/**
	 * Add notification to queue
	 *
	 * @param string $type     Notification type (success, error, warning, info)
	 * @param string $message  Notification message
	 * @param int    $duration Duration in milliseconds
	 */
	private function add_notification( $type, $message, $duration ) {
		$this->notifications[] = array(
			'type'     => $type,
			'message'  => $message,
			'duration' => $duration,
		);
	}

	/**
	 * Render queued notifications
	 */
	public function render_notifications() {
		if ( empty( $this->notifications ) ) {
			return;
		}

		echo '<script type="text/javascript">';
		echo '(function($) {';
		echo '$(document).ready(function() {';

		foreach ( $this->notifications as $notification ) {
			printf(
				'FreshRankNotifications.show("%s", %s, %d);',
				esc_js( $notification['type'] ),
				wp_json_encode( $notification['message'] ),
				intval( $notification['duration'] )
			);
		}

		echo '});';
		echo '})(jQuery);';
		echo '</script>';

		// Clear notifications after rendering
		$this->notifications = array();
	}
}
