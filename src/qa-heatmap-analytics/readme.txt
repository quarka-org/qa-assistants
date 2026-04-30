=== QA Assistants - Driven by data ===
Contributors: QuarkA
Tags: analytics, assistants, heatmap, insights, privacy-friendly
Tested up to: 6.9
Requires at least: 5.9
Stable tag: 5.2.0.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Let your data speak — assistants with different perspectives help you understand your site, alongside heatmaps and replays.

== Description ==

QA Assistants goes beyond analytics — it’s a companion that helps your data speak, with Assistants that reveal insights you can act on.

Each Assistant offers a unique way to look at your site — from quick overviews to social trends and growth insights.  
You can still explore familiar tools like heatmaps, session replays, and reports — and Assistants will gradually bring more context to them.

No complex setup or technical skills needed.  
QA Assistants makes discovering your site’s stories simple, visual, and a little fun too.


== Features ==

### Assistants — your data-driven companions to explore your site
Each Assistant offers a unique way to understand your site.  
From traffic overviews to social engagement or content insights, they highlight what truly matters — in plain words you can grasp.  
More Assistants will keep joining, each bringing a new perspective to your data.

**New:** The built-in **Page Analysis Assistant** lets you view pageviews, key metrics, and device-specific heatmaps directly from the page you're browsing — a lightweight, chatbot-style helper for logged-in admins.


### Heatmaps & Session Replays — see how visitors behave
Visualize how people interact with your site: where they click, scroll, and pause.  
Session Replays let you follow their real journeys, helping you discover friction points and hidden opportunities.

### Reports & Trends — your site at a glance
Access clear, intuitive charts and summaries that show what’s working and where to improve.  
Switch to **Advanced Mode** to unlock detailed metrics and comparison tools.

### Cookie-less Tracking — privacy made simple
Track user behavior responsibly.  
QA Assistants includes a cookie-less tracking mode, so you can comply with privacy rules without losing insight.

### Built for WordPress — light, secure, and extendable
Designed to blend seamlessly with your dashboard.  
QA Assistants follows WordPress coding standards, loads only what’s needed, and supports modular extensions for future Assistants.
All of these features are completely free — just install and start exploring.


== Important Notes ==

* QA Assistants collects data in real time, but analytical reports are processed overnight.  
  You can see live visit counts immediately, while detailed insights (used by Assistants) become available the following day.

* Please do not compress or minify JavaScript used by QA Assistants or WordPress.  
  Some optimization plugins may interfere with tracking; exclude QA Assistants–related scripts if needed.

* Make sure your PHP memory limit is sufficient for data processing.  
  If your server uses a very low limit (for example, 256 MB or less), some processes may fail.

* For detailed technical guidance or troubleshooting steps,  
  please see the [Documentation](https://docs.quarka.org/).


== Frequently Asked Questions ==

= What are Assistants? =

Assistants are smart companions that help you understand your site from different perspectives — such as overview, SEO, social engagement, or growth.  
Each Assistant highlights insights in its own way, turning data into simple, meaningful guidance.


= Do I need all Assistants to use QA Assistants? =

No. You can start with the basic setup and add Assistants as you like.  
They are modular extensions — each one focuses on a different aspect of your site.


= Can I still use Heatmaps and Session Replays? =

Absolutely. Heatmaps and replays remain part of the QA experience.  
They work alongside your Assistants, helping you see what happens on your pages in real time.


= Is there a limit to the number of Heatmap pages? =

No. You can view and analyze heatmaps for all pages on your site without limitation.


= Does QA Assistants count bot data? =

No. Major bots such as Googlebot are automatically excluded from tracking.  
However, if you need stricter control, consider using a dedicated bot-blocking plugin.


= Can I use a cache plugin? =

Some cache plugins may rewrite or minify JavaScript automatically, which can interfere with tracking.  
If you encounter issues, see the [Documentation](https://docs.quarka.org/) for setup tips.


= Does QA Assistants require cookies to track visitors? =

No. It supports a cookie-less tracking mode for privacy-friendly analytics.  
You can measure user behavior safely even if visitors reject consent banners.


= What happened to QA Analytics? =

QA Assistants is the new, unified version of those projects — redesigned for better usability, performance, and future expandability.  
Your existing data and settings remain compatible.


= Will there be more Assistants in the future? =

Yes! We’re continuously developing new Assistants for different viewpoints — from marketing and UX to performance insights.  
In the future, we also plan to open the Assistants framework for developers who want to create their own.  
Stay tuned for updates on the [official site](https://quarka.org/en-assistants/).


== Screenshots ==

1. Choose your Assistant — Overview, Social, Growth, and more.
2. Let your Assistant suggest what matters — see quick analyses and helpful tips.
3. View visit data and reports with clear graphs and tables.
4. See how visitors interact through heatmaps.
5. Replay real sessions to understand user behavior.


== Changelog ==

= 5.1.3.0 =
*Release Date: January 19, 2026*

- Fixed minor issues on the Realtime screen
- Improved compatibility in PHP 8.x environments
- Internal adjustments and maintenance updates


= 5.1.2.0 =
*Release Date: December 18, 2025*

- Improved compatibility with PHP 8.x.


= 5.1.1.0 =
*Release Date: December 5, 2025*

- Added a link to the official X (Twitter) account in the plugin footer.


= 5.1.0.0 =
*Release Date: November 28, 2025*

- Added the new **Page Analysis Assistant**, an on-page tool for viewing key metrics and accessing heatmaps.
- Improved UI across Heatmap notifications and admin settings.
- Enhanced data cleanup and performance.
- Minor bug fixes.


= 5.0.1.1 =
*Release Date: November 11, 2025*

- Improved data retention period configuration.

= 5.0.1.0 =
*Release Date: November 4, 2025*

- Rebranded the plugin name to **QA Assistants** (formerly **QA Assistant** / **QA Analytics**).

= 5.0.0.0 =
*Release Date: November 2, 2025*

- Initial release of **QA Assistant**, successor to **QA Analytics**.
- Introduces the **Assistants** feature to extend functionality via add-ons.
- Reports are now split into dedicated admin submenus (previously all under **Home**).
- Refined **Heatmap view** toolbar/layout and styles; added a toolbar action to create a new heatmap version.
- Introduced dedicated config file **qa-config.php** to define PV limits and data retention.
- Minor bug fixes, stability improvements, and compatibility updates.
