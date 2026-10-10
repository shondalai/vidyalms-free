=== VidyaLMS ===
Contributors: maverick
Tags: lms, courses, e-learning, quiz, education
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Build courses, quizzes and assignments, track learner progress, and award certificates. Connect WooCommerce to sell course access.

== Description ==

VidyaLMS turns a WordPress site into a course platform. Build a course out of modules and
lessons, set a completion rule, and learners enrol, work through it, and receive a certificate
with a verifiable code.

The free features listed below work without a VidyaLMS licence key or a Shondalai account.
The free plugin does not send usage telemetry or make licence-verification requests. Interface assets
are served locally. Optional services and externally hosted lesson content are explained below.

**Courses**

* Courses made of modules and lessons, ordered by drag and drop.
* Five lesson types: reading, video, downloadable resource, quiz and assignment.
* Draft, published and archived states, plus per-course prerequisites.
* Free preview lessons, so part of a course can be readable before enrolling.
* Each course gets its own permalink. VidyaLMS controls course visibility and enrolment;
  private and unlisted courses are excluded from WordPress search, feeds and sitemaps.

**Quizzes**

* Six question types: single choice, multiple choice, true or false, fill in the blank, short
  answer and ordering.
* Pass marks, attempt limits, time limits and a choice of when results are shown.
* Attempts are append-only, so re-grading keeps the history instead of overwriting it.
* Correct answers never reach the browser before an attempt is submitted.

**Assignments**

* File uploads and written responses, with allowed types, size and count limits.
* Manual grading with points and feedback, and optional resubmission.
* Uploaded work uses private storage and expiring download links. Private storage must be
  outside the public web folder (see Installation).

**Enrolment and progress**

* Open self-enrolment, manual enrolment by an administrator, or a WooCommerce purchase.
* Access is recorded separately from enrolment, so expiring or withdrawing access suspends the
  enrolment and never deletes a learner's progress.
* Per-lesson progress with resumable video positions.
* Completion rules: every lesson, a percentage of lessons, or passing the final quiz.

**Certificates**

* Issued automatically on completion, once per enrolment.
* Certificates are HTML documents. Learners download their copy and print it from the browser.
* Every certificate carries a verification code and a public verification page.

**Notifications and reporting**

* Transactional email on enrolment, completion and grading, sent with `wp_mail` and your site's
  own mail configuration.
* An activity log of the whole learning timeline, and a course overview report.

The AI workspace manages permissions and data-use policy for optional model integrations.
Free does not include a model connection or run AI requests on its own. Installing an
integration is optional; the course, quiz and certificate features work without it.
Network settings apply only to external services you choose to connect.

**WooCommerce**

* WooCommerce owns all payments, orders, refunds, invoices and subscriptions.
* Connect products and courses in VidyaLMS > Settings > Commerce & access. Product and variation
  settings in WooCommerce also provide a course selector.
* Choose a background enrolment account with permission to enrol learners in the same settings
  section. Customers must sign in or create an account when buying mapped course access.
* Confirmed paid orders grant access. Item refunds remove only the refunded course access;
  partial fee refunds preserve access and learner progress is retained.
* WooCommerce Subscriptions grants access for the active term, including prepaid cancellation.
  Resumptions and renewals refresh access; subscription lines never create lifetime order access.
* My learning links to WooCommerce My Account for shop orders and subscriptions.
* Missed events and failed access changes are retried and reconciled automatically through WP-Cron.
* WooCommerce HPOS and Checkout Blocks are supported. Without WooCommerce this bridge is inactive.

**Privacy**

* A personal data exporter and eraser are registered with WordPress, so Tools > Export Personal
  Data and Tools > Erase Personal Data cover the whole learning record: enrolments, access
  records, lesson and course progress, quiz attempts and answers, assignment submissions,
  certificates, the activity log and course email.
* Erasure removes the learner's own records. Where a row belongs to somebody else (a course this
  person authored, a grade this person awarded on another learner's work) the row is kept and the
  identity is removed, so no other learner loses their own results.
* Erasing learning data keeps a minimal record of previously processed purchases so background
  order updates cannot restore the access you erased. WooCommerce manages its own customer and
  order retention separately.

**For developers**

* A REST API at `wp-json/vidyalms/v1`, authenticated with the usual WordPress cookie and nonce or
  an application password.
* Capabilities on roles for teaching, grading, enrolling and reporting.
* WordPress actions for every domain event (`vidyalms/course_completed` and the rest), each fired
  once with a stable idempotency key, so integrations can attach without polling.
* The learning engine is a CMS-agnostic PHP library shipped inside the plugin. The same engine
  runs the Joomla edition, so behaviour does not drift between platforms.

**VidyaLMS Pro: optional paid add-on**

For more assessment and course delivery options, VidyaLMS Pro installs alongside the free
plugin and adds:

* Advanced quiz questions, including matching, numeric answers, hotspots, drawings and recorded
  responses.
* Reusable question banks with version history and random question pools.
* Question import from QTI, Moodle XML and GIFT, plus QTI export.
* Cohorts, scheduled lesson releases, learning paths and programmes.
* PDF certificates, generated on your own server.
* A drag-and-drop certificate designer and certificate expiry policies.
* Excel and PDF report exports, plus quiz item analysis.

These features are supplied by the separate Pro add-on and are not included in this download.
Your existing courses, learners and progress stay in place when you add Pro. The free plugin
continues to work without a Pro purchase.

[Compare VidyaLMS Free and Pro](https://shondalai.com/products/vidyalms#vl-compare).

== External services ==

You choose whether to use externally hosted lesson content. A course can run entirely with
local reading lessons, files and assessments.

* YouTube: when an author embeds a YouTube video, opening that lesson loads the player from
  youtube-nocookie.com. The learner's browser contacts Google to request the video and player
  resources, sharing the video identifier, IP address and browser information. Playback can
  involve further requests and cookies under Google's policies.
  Terms: https://www.youtube.com/t/terms
  Privacy: https://policies.google.com/privacy
* Vimeo: when an author embeds a Vimeo video, opening that lesson loads player.vimeo.com.
  The browser shares the video identifier, IP address and browser information with Vimeo.
  Terms: https://vimeo.com/terms
  Privacy: https://vimeo.com/privacy
* Other remote media: if an author adds an externally hosted image, audio file or video,
  the learner's browser requests that resource from the host chosen by the author. Review
  that host's terms and privacy policy before using it in a course.
* WooCommerce: the optional bridge works locally with your installed WooCommerce extension.
  VidyaLMS does not send card details or process payments. Payment services, invoices and
  subscriptions are configured in WooCommerce and its extensions; those services have their
  own terms and privacy policies.

Explain any external lesson services in your site's privacy policy and configure consent
before embedding content where required. VidyaLMS does not contact these video providers
merely because the plugin is installed or activated.

== Source code ==

The complete source of this plugin, including the uncompiled interface, is public at
https://github.com/shondalai/vidyalms-free. The JavaScript and CSS in assets/app/ are compiled
from packages/ui in that repository, together with the API client in packages/api-sdk, using
the package-lock.json files recorded there. The repository's README explains how to rebuild
them. The WordPress build uses the React libraries supplied by WordPress.

Third-party notices for the libraries in the compiled interface are in THIRD-PARTY-NOTICES.txt.
The bundled PSR interfaces in vendor-psr/ carry their own licence files.

== Installation ==

1. Upload the `vidyalms` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add
   New > Upload Plugin.
2. Activate the plugin through the Plugins screen. Activation creates the VidyaLMS tables and the
   roles and capabilities.
3. Go to VidyaLMS > Courses and create your first course.
4. Go to Settings > Permalinks, choose Post name or another non-Plain structure, and save.
   Learner pages and certificate verification links need these permalinks.
5. Check VidyaLMS > System health before learners upload work. VidyaLMS sets up a private
   folder outside your public website when hosting permissions allow it. If the storage
   notice appears, ask your host to set VIDYALMS_PRIVATE_DIR in wp-config.php to a writable
   directory outside the public web folder. Public course images work independently.
   The plugin refuses public storage locations, even with .htaccess or web.config rules.
   Your host must also ensure the folder has no public web-server alias.

   For example, if WordPress is installed in /var/www/example/public, your host can create
   /var/www/example/vidyalms-private and add this to wp-config.php:

   `define('VIDYALMS_PRIVATE_DIR', '/var/www/example/vidyalms-private');`

   PHP needs read and write permission for that folder. On multisite, each site uses its own
   subfolder. Include this directory in your backups. File uploads are limited to 512 MiB
   and 500 retained files per user, with at most 50 uploads per hour.

Requires PHP 8.1 or newer and WordPress 6.6 or newer.
PHP must have mbstring, dom, gd, iconv and zlib enabled. The database must be MySQL
5.7.8 or MariaDB 10.2.7 or newer, with InnoDB tables. These are checked during activation.
Encrypted organisation invitations require HTTPS and PHP's sodium extension. Ask your host
to enable both if the invitation controls are unavailable.

== Frequently Asked Questions ==

= Is anything locked or limited in this plugin? =

The free plugin has no trial period or licence requirement, and no limit on courses, lessons,
quiz questions, assignments, certificates or learners. Pro features are supplied by the
separate paid add-on described above.

= Does the plugin contact your servers? =

VidyaLMS does not contact Shondalai for licence verification or usage tracking. WordPress
handles directory updates. Optional video embeds, payment extensions and other services you
choose can contact their own providers; see the External services section.

= Do I need WooCommerce? =

Only to sell courses. Everything else works without it, and none of the WooCommerce code loads
when WooCommerce is not active.

= Where is course content stored? =

In the plugin's own tables. The `vidyalms_course` post type provides course permalinks and
theme integration. Its native REST and discovery surfaces are disabled to protect course
visibility. Lessons, attempts and progress are not stored in post meta.

= Can I move a site with courses on it? =

Yes. Back up and move the VidyaLMS tables with the rest of the database, the public media, and
the private storage directory. Update VIDYALMS_PRIVATE_DIR for the destination server.
Private storage ownership is bound to the site address and the resolved WordPress
installation directory. Give a staging clone its own private directory even when it uses
the production URL. After a legitimate move, ask the server owner to verify that no other
site uses the old directory before renewing its ownership marker with the documented
PrivateStorageOwnership migration helper. Old or mismatched markers fail closed.

= Will an update lose my learner data? =

No. Updates migrate the schema in place; learner records, attempts and certificates are never
dropped by an update.

= Does it work with a multisite network? =

Each site in a network gets its own courses and its own tables. Network-wide reporting across
sites is not part of this plugin.
When VidyaLMS is loaded for a network site-deletion request, permanent site deletion also
removes that site's Free and Pro tables and its owned files. Keep the plugin network-active
when deleting subsites; a plugin inactive on the deleting request cannot run cleanup.
Local LearnDash and Tutor LMS database imports are unavailable on multisite until their
shared learner metadata can be safely scoped to an individual site. File-based imports
remain available.

= What happens when I delete the plugin? =

Data is preserved by default. To delete a site's courses, learner records, course categories
and owned files, enable the Plugin removal checkbox on that site's VidyaLMS Settings page
before deleting the plugin. Each network site has its own preference. Deactivation only
stops scheduled work. Keep a backup before choosing permanent removal.

== Screenshots ==

1. Course builder in focus mode with modules and a rich-text lesson editor.
2. Quiz authoring with question ordering, points, pass mark and time limit.
3. Learner course player with a searchable curriculum, lesson content and saved progress.
4. Practical assignment submissions with configurable file limits and instructor approval.

== Changelog ==

= 1.0.1 =
* PDF certificate rendering moved to the VidyaLMS Pro add-on. Certificates in this plugin are
  HTML documents with a verification code that learners print from the browser.
* Certificate designs that chose the PDF renderer keep issuing, as HTML. To republish one,
  choose the Designed certificate renderer.
* Smaller package: the PDF engine, source maps, the copy of the interface source and the
  translation template are no longer included. Translations come from translate.wordpress.org.
* The full source, including the uncompiled interface, is published at
  https://github.com/shondalai/vidyalms-free.
* Security: private downloads, uploads, reports and organisation records are restricted to
  authorised users, and assignment attachments are verified against their stored bytes,
  including each assignment's size and file-type limits.
* Security: report scope is checked again before a saved export is returned to a staff member
  who has lost access.
* Fix quiz scoring, course visibility, lesson availability and learner progress checks.
* Privacy: improved erasure and retention, including unattached learner uploads and
  required-learning records. Shared schedules are kept when a staff account is erased, and
  AI drafts that mix several learners' text wait for an administrator's review.
* Harden private storage defaults, import handling and certificate rendering.
* Background jobs, scheduled report emails and WooCommerce enrolment reconciliation run more
  reliably; report links stay usable for the report retention period.
* Fix forms on WordPress's React 18, the multisite lifecycle and package compatibility.
* Experimental database importers are hidden from the normal import list. Moodle imports keep
  supported media and readable question text.
* Automation labels are translatable, and an unenrolment rule can name the rule whose access
  it reverses.
* Localized database duplicate-key errors are recognised.
* Schema migrations for privacy file deletion, notification retention and unit-scoped imports.

= 1.0.0 =
* First release.
* Courses, modules and five lesson types.
* Quizzes with six question types, attempt limits, time limits and pass marks.
* Assignments with private file uploads and manual grading.
* Open and manual enrolment, entitlements, expiry that suspends rather than deletes.
* Lesson and course progress, resumable video positions, three completion rules.
* Certificates with public verification.
* Transactional email through `wp_mail`, and an activity log.
* REST API at `wp-json/vidyalms/v1`.
* Personal data exporter and eraser.
* Optional WooCommerce course access, including WooCommerce Subscriptions.

== Upgrade Notice ==

= 1.0.1 =
Security and privacy fixes. PDF certificates now need the VidyaLMS Pro add-on; without it,
certificates, including designs that chose PDF, are HTML documents that print from the browser.

= 1.0.0 =
First release. Requires PHP 8.1 and WordPress 6.6.
