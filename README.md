# Kunena Discord Webhook Plugin

If this plugin saves you time and makes your forum community more active, please consider supporting my work! ☕

[![Ko-Fi](https://img.shields.io/badge/Ko--fi-F16061?style=for-the-badge&logo=ko-fi&logoColor=white)](https://ko-fi.com/ailocksmith)

A lightweight Joomla system plugin that automatically posts new Kunena forum messages to Discord via webhooks.

## Features

- 🔗 **Real-time Discord notifications** for new forum posts and replies
- 🎨 **Customizable embed colors** with predefined options or custom hex colors
- ⚡ **Lightweight and non-intrusive** - only activates during Kunena interactions
- 🔧 **Configurable content limits** to ensure Discord compatibility
- 📝 **Smart text processing** with BBCode and HTML cleanup
- 🚫 **Duplicate prevention** system to avoid spam
- 🐛 **Debug logging** for easy troubleshooting

## Installation

1. Download the plugin files
2. Create a zip file containing:
   - `kunenadiscord.php`
   - `kunenadiscord.xml`
   - `language/en-GB/en-GB.plg_system_kunenadiscord.ini`
3. Install via Joomla Admin → Extensions → Install
4. Enable the plugin in Extensions → Plugins
5. Configure your Discord webhook URL and settings

## Configuration

### Required Settings
- **Discord Webhook URL**: Get this from your Discord server settings
  - Server Settings → Integrations → Webhooks → Create Webhook

### Optional Settings
- **Footer Text**: Custom text for Discord message footer (default: "Kunena Forum")
- **Embed Color**: Choose from predefined colors or enter custom hex code
- **Content Limit**: Maximum characters for post content (100-3500)
- **Debug Mode**: Enable detailed logging for troubleshooting

## How It Works

This plugin uses a unique **database monitoring approach** rather than hooking into Kunena's events directly.

### Why Not Use Kunena's Plugin System?

During development, we initially attempted to use Kunena's built-in plugin system to capture new messages. However, this approach proved problematic:

- **Complex Event System**: Kunena's plugin architecture is intricate and not well-documented
- **Version Compatibility**: Different Kunena versions have varying plugin hooks
- **Event Timing Issues**: Messages weren't always fully processed when events fired
- **Missing Dependencies**: Required understanding of Kunena's internal structure

### Our Database Monitoring Solution

Instead, we implemented a smarter approach:

1. **Joomla System Events**: Uses standard Joomla `onAfterRender()` and `onAfterRoute()` events
2. **Context Detection**: Only activates when users are interacting with Kunena
3. **Database Polling**: Queries for messages created in the last 30 seconds
4. **Duplicate Prevention**: File-based tracking prevents multiple notifications

```php
// Only monitors during Kunena interactions
if ($option === 'com_kunena' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    register_shutdown_function([$this, 'checkForRecentPosts']);
}
```

This approach is:
- ✅ **More reliable** - doesn't depend on Kunena's internal changes
- ✅ **Version agnostic** - works across different Kunena versions
- ✅ **Lightweight** - minimal resource usage
- ✅ **Non-intrusive** - doesn't interfere with other Joomla components

## Requirements

- Jooml 5.x 
- Kunena Forum 6.x 
- PHP 7.4+
- Discord server with webhook permissions

May work with other Joomla / Kunena versions. Tested working with Joomla 5.2.3 and Kunena 6.3.8.

## Discord Message Format

The plugin creates rich Discord embeds with:
- **Author**: Forum user's name
- **Title**: Topic subject (or "Re: Topic" for replies)
- **Content**: Message text (cleaned of BBCode/HTML)
- **Link**: Direct link back to the forum post
- **Timestamp**: When the message was posted
- **Color**: Configurable embed border color

## Troubleshooting

### Common Issues

**Messages not appearing in Discord:**
- Check webhook URL is correct
- Verify plugin is enabled
- Enable debug mode and check logs at `/logs/kunenadiscord.php`

**Payload too large errors:**
- Reduce content character limit in plugin settings
- Long posts are automatically truncated

**Wrong forum links:**
- Plugin auto-detects Kunena menu items
- Ensure Kunena component is properly configured

### Debug Logging

Enable debug mode in plugin settings to see detailed logs:
- Message processing status
- Payload sizes
- Discord API responses
- Error details

## Security Notes

- Webhook URLs should be kept private
- Plugin only reads from database (no writes to Kunena tables)
- All operations are wrapped in try-catch blocks
- No sensitive data is logged

## License

This plugin is open source. Feel free to modify and distribute according to your needs.

## Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Submit a pull request

## Support

For issues or questions:
1. Check the debug logs first
2. Ensure Discord webhook URL is valid
3. Verify Kunena is working properly
4. Open an issue with detailed information

---

**Made with ❤️ for the Joomla and Kunena community**
