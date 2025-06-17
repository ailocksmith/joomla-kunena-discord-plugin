<?php
/**
 * Kunena Discord Webhook Plugin - System Plugin with Database Monitoring
 * File: kunenadiscord.php
 * 
 * This plugin monitors database insertions specifically for Kunena messages
 * FIXED: Now properly extracts content from kunena_messages_text table
 * Enhanced with configurable colors, footer text, and payload size validation
 * 
 * * @copyright  Copyright (C) 2024. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

class PlgSystemKunenadiscord extends CMSPlugin
{
    protected $autoloadLanguage = true;
    
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);
        
        // Set up logging
        Log::addLogger(
            ['text_file' => 'kunenadiscord.php'],
            Log::ALL,
            ['kunenadiscord']
        );
        
        // Constructor logging removed for cleaner logs
    }

    /**
     * Debug logging helper
     */
    private function debugLog($message, $level = Log::INFO)
    {
        if ($this->params->get('debug', 1)) {
            Log::add('KunenaDiscord: ' . $message, $level, 'kunenadiscord');
        }
    }

    /**
     * System event that fires after the application has been rendered
     * We use this to monitor for new Kunena posts that were just created
     */
    public function onAfterRender()
    {
        // Only run on frontend
        $app = Factory::getApplication();
        if ($app->isClient('administrator')) {
            return;
        }

        // Only check if we're in a Kunena context
        $option = $app->input->get('option');
        $task = $app->input->get('task');
        $view = $app->input->get('view');
        
        // Check if this looks like a Kunena post submission
        if ($option === 'com_kunena' && 
            ($task === 'post' || $task === 'reply' || $view === 'topic')) {
            
            $this->checkForNewKunenaPosts();
        }
    }

    /**
     * Alternative approach: Monitor the response after Kunena operations
     */
    public function onAfterRoute()
    {
        $app = Factory::getApplication();
        
        // Only on frontend
        if ($app->isClient('administrator')) {
            return;
        }

        $option = $app->input->get('option');
        $task = $app->input->get('task');
        
        // If this is a Kunena POST request, it might be a new message
        if ($option === 'com_kunena' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Add a callback to check for new posts after the request is processed
            register_shutdown_function([$this, 'checkForRecentPosts']);
        }
    }

    /**
     * Check for very recent Kunena posts (last 30 seconds)
     * This runs after the page has been fully processed
     */
    public function checkForRecentPosts()
    {
        try {
            $db = Factory::getDbo();
            
            // Check for messages created in the last 30 seconds
            $query = $db->getQuery(true)
                ->select('m.*')
                ->from('#__kunena_messages AS m')
                ->where('m.time > ' . (time() - 30))
                ->order('m.time DESC');
            
            $db->setQuery($query, 0, 5); // Limit to 5 most recent
            $messages = $db->loadObjectList();
            
            foreach ($messages as $message) {
                // Check if we've already processed this message
                if (!$this->hasProcessedMessage($message->id)) {
                    $this->debugLog('Processing new message ID: ' . $message->id);
                    $this->processKunenaMessage($message);
                    $this->markMessageAsProcessed($message->id);
                }
            }
            
        } catch (Exception $e) {
            $this->debugLog('ERROR in checkForRecentPosts: ' . $e->getMessage(), Log::ERROR);
        }
    }

    /**
     * Check if we've already processed this message
     * Uses a simple file-based tracking system
     */
    protected function hasProcessedMessage($messageId): bool
    {
        $trackingFile = JPATH_CACHE . '/kunena_discord_processed.txt';
        
        if (!file_exists($trackingFile)) {
            return false;
        }
        
        $processedIds = file_get_contents($trackingFile);
        return strpos($processedIds, "|$messageId|") !== false;
    }

    /**
     * Mark message as processed
     */
    protected function markMessageAsProcessed($messageId): void
    {
        $trackingFile = JPATH_CACHE . '/kunena_discord_processed.txt';
        
        // Keep only last 100 processed IDs to prevent file from growing too large
        $processedIds = '';
        if (file_exists($trackingFile)) {
            $processedIds = file_get_contents($trackingFile);
            $ids = explode('|', $processedIds);
            if (count($ids) > 100) {
                $ids = array_slice($ids, -50); // Keep last 50
                $processedIds = implode('|', $ids);
            }
        }
        
        $processedIds .= "|$messageId|";
        file_put_contents($trackingFile, $processedIds);
    }

    /**
     * Alternative method: Check for new posts by looking at recent database activity
     */
    protected function checkForNewKunenaPosts(): void
    {
        try {
            $db = Factory::getDbo();
            
            // Get the most recent message
            $query = $db->getQuery(true)
                ->select('m.*')
                ->from('#__kunena_messages AS m')
                ->order('m.time DESC');
            
            $db->setQuery($query, 0, 1);
            $latestMessage = $db->loadObject();
            
            if ($latestMessage) {
                // If the message is very recent (last 10 seconds), process it
                if ($latestMessage->time > (time() - 10)) {
                    if (!$this->hasProcessedMessage($latestMessage->id)) {
                        $this->debugLog('Processing fresh message: ' . $latestMessage->id);
                        $this->processKunenaMessage($latestMessage);
                        $this->markMessageAsProcessed($latestMessage->id);
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->debugLog('ERROR in checkForNewKunenaPosts: ' . $e->getMessage(), Log::ERROR);
        }
    }

    /**
     * Process a Kunena message and send to Discord
     */
    protected function processKunenaMessage($message): void
    {
        try {
            // Get webhook URL
            $webhook = $this->params->get('webhook', '');
            if (empty($webhook)) {
                $this->debugLog('ERROR: Webhook URL not configured', Log::ERROR);
                return;
            }

            // Validate webhook URL
            if (!filter_var($webhook, FILTER_VALIDATE_URL) || strpos($webhook, 'discord.com/api/webhooks/') === false) {
                $this->debugLog('ERROR: Invalid Discord webhook URL', Log::ERROR);
                return;
            }

            // Extract message data
            $data = $this->extractMessageData($message);

            // Send to Discord
            $this->sendToDiscord($webhook, $data);

        } catch (Exception $e) {
            $this->debugLog('ERROR in processKunenaMessage: ' . $e->getMessage(), Log::ERROR);
        }
    }

    /**
     * Extract data from Kunena message with enhanced truncation
     */
    protected function extractMessageData($message): array
    {
        $data = [
            'author' => 'Unknown Author',
            'subject' => 'New Forum Post',
            'content' => 'No Content',
            'url' => Uri::root()
        ];

        try {
            $db = Factory::getDbo();

            // Get author name (truncate if too long)
            if (!empty($message->name)) {
                $data['author'] = $this->truncateText($message->name, 250);
            } elseif ($message->userid > 0) {
                $user = Factory::getUser($message->userid);
                $authorName = $user->name ?: $user->username;
                $data['author'] = $this->truncateText($authorName, 250);
            }

            // Get topic subject (truncate if too long)
            if ($message->parent == 0) {
                $data['subject'] = $this->truncateText($message->subject ?: 'New Topic', 250);
            } else {
                $query = $db->getQuery(true)
                    ->select('subject')
                    ->from('#__kunena_messages')
                    ->where('thread = ' . (int)$message->thread)
                    ->where('parent = 0');
                
                $db->setQuery($query);
                $topicSubject = $db->loadResult();
                $replySubject = $topicSubject ? "Re: $topicSubject" : 'Reply to Topic';
                $data['subject'] = $this->truncateText($replySubject, 250);
            }

            // Get message content from kunena_messages_text table
            $query = $db->getQuery(true)
                ->select('message')
                ->from('#__kunena_messages_text')
                ->where('mesid = ' . (int)$message->id);
            
            $db->setQuery($query);
            $messageText = $db->loadResult();

            if (!empty($messageText)) {
                // Process the content
                $content = $messageText;
                
                // Handle BBCode if present (basic cleanup)
                $content = preg_replace('/\[([^\]]*)\]/', '', $content);
                
                // Clean HTML
                $content = strip_tags($content);
                $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
                $content = trim($content);
                
                // Get configurable content limit (default 1500 to be safe)
                $contentLimit = (int)$this->params->get('content_limit', 1500);
                $data['content'] = $this->truncateText($content, $contentLimit);
                
                if (empty($data['content'])) {
                    $data['content'] = '[Content could not be processed]';
                }
            } else {
                $data['content'] = '[No content found]';
                $this->debugLog('No content found in kunena_messages_text for message ID: ' . $message->id);
            }

            // Construct URL
            $itemId = $this->getKunenaItemId();
            $data['url'] = Uri::root() . "index.php?option=com_kunena&view=topic&catid={$message->catid}&id={$message->thread}&Itemid={$itemId}#{$message->id}";

        } catch (Exception $e) {
            $this->debugLog('ERROR extracting message data: ' . $e->getMessage(), Log::ERROR);
            $data['content'] = '[Error extracting content: ' . $e->getMessage() . ']';
        }

        return $data;
    }

    /**
     * Smart text truncation with word boundary respect
     */
    protected function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        // Try to truncate at word boundary
        $truncated = substr($text, 0, $maxLength - 3);
        $lastSpace = strrpos($truncated, ' ');
        
        if ($lastSpace !== false && $lastSpace > ($maxLength * 0.7)) {
            // If we found a space in the last 30% of the text, use it
            return substr($text, 0, $lastSpace) . '...';
        } else {
            // Otherwise, hard truncate
            return substr($text, 0, $maxLength - 3) . '...';
        }
    }

    /**
     * Get Kunena menu item ID
     */
    protected function getKunenaItemId(): int
    {
        try {
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('id')
                ->from('#__menu')
                ->where('link LIKE ' . $db->quote('%option=com_kunena%'))
                ->where('published = 1')
                ->order('id ASC');
            
            $db->setQuery($query, 0, 1);
            return (int) $db->loadResult() ?: 0;
        } catch (Exception $e) {
            $this->debugLog('ERROR getting Kunena item ID: ' . $e->getMessage(), Log::ERROR);
            return 0;
        }
    }

    /**
     * Get the configured embed color
     */
    protected function getEmbedColor(): int
    {
        // Check if custom color is set first
        $customColor = trim($this->params->get('custom_color', ''));
        if (!empty($customColor)) {
            // Remove # if present
            $customColor = ltrim($customColor, '#');
            
            // Validate hex color (6 characters, only hex digits)
            if (preg_match('/^[0-9A-Fa-f]{6}$/', $customColor)) {
                return hexdec($customColor);
            } else {
                $this->debugLog('Invalid custom color format: ' . $customColor . ', using default', Log::WARNING);
            }
        }
        
        // Use selected color from dropdown
        $selectedColor = $this->params->get('embed_color', '7289DA');
        return hexdec($selectedColor);
    }

    /**
     * Send to Discord with configurable color and payload validation
     */
    protected function sendToDiscord(string $webhook, array $data): void
    {
        try {
            // Get footer text and color from plugin parameters
            $footerText = $this->truncateText($this->params->get('footer_text', 'Kunena Forum'), 2040);
            $embedColor = $this->getEmbedColor();
            
            // Create Discord payload
            $payload = [
                'embeds' => [
                    [
                        'title' => $data['subject'],
                        'description' => $data['content'],
                        'url' => $data['url'],
                        'color' => $embedColor,
                        'author' => [
                            'name' => $data['author']
                        ],
                        'timestamp' => date('c'),
                        'footer' => [
                            'text' => $footerText
                        ]
                    ]
                ]
            ];

            // Validate payload size
            $jsonPayload = json_encode($payload);
            $payloadSize = strlen($jsonPayload);
            
            $this->debugLog("Payload size: $payloadSize bytes, Color: #" . dechex($embedColor));
            
            if ($payloadSize > 6000) {
                // Emergency truncation - this shouldn't happen with our limits above
                $this->debugLog('WARNING: Payload too large, emergency truncation', Log::WARNING);
                
                // Drastically reduce content size
                $newContentLimit = 1000;
                $payload['embeds'][0]['description'] = $this->truncateText($data['content'], $newContentLimit);
                $jsonPayload = json_encode($payload);
                
                // If still too large, strip down to basics
                if (strlen($jsonPayload) > 6000) {
                    $payload['embeds'][0]['description'] = '[Post too large for Discord - view on forum]';
                    $jsonPayload = json_encode($payload);
                }
            }

            // Send HTTP request
            $http = HttpFactory::getHttp();
            $response = $http->post($webhook, $jsonPayload, [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Joomla-Kunena-Discord-Plugin/1.0'
            ]);

            if ($response->code >= 200 && $response->code < 300) {
                $this->debugLog('SUCCESS: Message sent to Discord');
            } else {
                $this->debugLog('ERROR: Discord error: ' . $response->code . ' - ' . $response->body, Log::ERROR);
                
                // Log specific Discord error codes
                if ($response->code == 400) {
                    $this->debugLog('Discord 400 error - likely payload too large or malformed', Log::ERROR);
                }
            }

        } catch (Exception $e) {
            $this->debugLog('ERROR sending to Discord: ' . $e->getMessage(), Log::ERROR);
        }
    }
}