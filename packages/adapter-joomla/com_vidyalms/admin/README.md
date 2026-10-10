# VidyaLMS Joomla hosting

This file is installed at `administrator/components/com_vidyalms/README.md`.

## Certificate PDF worker (VidyaLMS Pro)

VidyaLMS certificates are HTML. PDF certificates come with VidyaLMS Pro, which
installs its own isolated renderer; this component ships no PDF engine, and a
site without Pro needs nothing from this section.

With Pro installed, PDF generation needs a PHP CLI executable, process functions
(`proc_open`, `proc_get_status`, `proc_terminate`, `proc_close`) and a writable
`sys_temp_dir` allowed by `open_basedir`. The Operations, Settings and
Certificates screens warn when the renderer reports that these checks fail. HTML
verification remains available.

If automatic PHP discovery is unsuitable, the server owner can define an absolute
executable path in a trusted bootstrap loaded by site, administrator, API and CLI:

```php
define('VIDYALMS_PHP_CLI', '/usr/bin/php8.3');
// Windows example instead: C:/php/8.3/php.exe
```

Use a path only, without command arguments. This is a server constant, never a
component option or request value. An invalid explicit value disables PDF
availability instead of selecting a different executable. The runtime checks
that the executable exists and is executable. Configure the required PHP
extensions in the selected CLI runtime, then issue a test certificate before
production use. Unset the constant to use automatic discovery.

## Private storage ownership and migration

System health shows the selected private directory. Restricted hosts can define
`VIDYALMS_PRIVATE_DIR` as an absolute writable directory outside all public roots,
in the same trusted bootstrap. Automatic setup uses a server-signed site-specific
location. No component option can authorize an arbitrary filesystem location.

Every Joomla private root has `.vidyalms-owner.json`, bound to the installation
path and server hostname. First writes claim empty roots only. Existing nonempty
unmarked roots, damaged markers and another installation's roots are refused.
Never fix a clone by replacing the original site's marker: give it a new empty
directory. Public images remain independent of private storage availability.

Before migration, stop web activity and workers, back up files, reject links and
shared use, and confirm every file belongs to this installation. Initialize a
new empty destination using this site's storage `ensurePrivateRoot()` before
copying the verified files, preserving its marker. Compare inventories, sizes and
SHA-256 hashes. Keep keys and relative paths. A server owner who verifies a legacy
unmarked root can obtain marker bytes from
`VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage::ownershipMarker(JPATH_ROOT)`
inside the installation's trusted Joomla CLI bootstrap and save them with
owner-only permissions. That helper returns bytes without modifying files.
Never overwrite a marker without offline ownership verification. Configure every
entry point, verify private upload/download and jobs, and remove any old public
copy while still paused. Configuration changes never move files.

## Uninstall and retained data

Pause requests/workers and record the private path before uninstall: settings
tables are removed. The package deletes generated `reports/`, `certificates/`
and `imports/` only after validating this installation's private ownership. A
warning means cleanup could not complete; inspect retained files separately.
Uninstall never claims unmarked/foreign directories or follows links.

Learner `submissions/` and `attempts/` remain unless the server owner explicitly
defines `VIDYALMS_REMOVE_LEARNER_FILES_ON_UNINSTALL` as boolean `true` in the
uninstall request's trusted bootstrap. Public `images/vidyalms`, private keys,
ownership metadata, guards, other private paths, and notification consent and
suppression tables remain. Apply your retention policy to those retained data;
verify the recorded path before manual deletion. Backups need their own retention
and removal process.

## Scheduler notifications

Free task notification mail defaults to disabled. Enable the desired outcomes
and choose recipient groups in Joomla Scheduled Tasks. Updates repair missing
Free notification fields without changing schedules, enabled states, selected
recipients or recreating deleted tasks.

## Windows organisation invitation key setup

The first invitation-key creation on Windows runs the readable packaged script
`src/Service/support/restrict-invitation-key-directory.ps1` using Windows PowerShell
`-File` with a literal directory argument. There is no encoded or interpolated
command payload. The child is hidden, profiles and interactive prompts are disabled,
and its execution-policy override applies only to that trusted invocation; machine
policy can still prevent it. No request input chooses the script or executable.

Before writing any key bytes, the script protects the private `.secrets` folder's
ACL for the current PHP service identity, SYSTEM and Administrators, checks its
trusted owner, and verifies the resulting access rules. Setup remains fail-closed
if that verification fails. An existing key is never replaced; if queued invitation
ciphertext exists and its key is missing, restore the protected backup.

Each setup attempt has a 10-second process deadline and up to one second to stop
the child. A durable `.secrets/organisation-invitations-acl.state.json` records a
15-minute retry window **before** launch; failures, timeouts and interrupted PHP
requests do not launch again on each kernel build. Concurrent attempts are refused
without waiting. Normal reads of an existing key launch no process. Unknown or
corrupt setup state also fails closed.

If invitations are unavailable, the server owner should inspect this state file
under the verified private root. Pause web requests and workers before recovery.
Confirm Windows PowerShell and PHP process functions are allowed, the `.secrets`
folder has no junctions/links, and its owner is the PHP service identity, SYSTEM or
Administrators. Run the packaged script under the **same identity as PHP** with
`-Directory` pointing to that dedicated folder; `-VerifyOnly` checks without changing
the ACL. Never apply it to a shared folder or a public site directory. A different
interactive administrator identity must not replace the PHP service's access.

After correcting the cause, either wait for the cooldown or, while all writers are
stopped and no setup process is running, remove only
`organisation-invitations-acl.state.json` to permit a fresh attempt. Never delete or
regenerate `organisation-invitations.key` to fix permissions. Back it up securely;
losing it makes queued encrypted invitation tokens unreadable. Web and CLI workers
must use an identity permitted by that ACL (prefer the same service identity).
# Refund review and retained groups

An amount-only partial refund does not identify the returned course. VidyaLMS
leaves existing access unchanged, refuses new grants from that order, and records
a refund-review warning in the sync queue and Joomla log. Review the order and
the learner's enrolments; explicit cumulative returned quantities for each mapped
line, including zero for unaffected lines, let automatic synchronization resume.
A full refund still revokes the purchase's access automatically.

Uninstall removes VidyaLMS's action-log registration and scheduled tasks. It keeps
the Joomla organisation-administrators user group because other components may
use its memberships and permissions. Review and remove that group through Joomla
Users if it is no longer needed.
