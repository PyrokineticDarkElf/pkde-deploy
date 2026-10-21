# Security policy

## Reporting a vulnerability

Please report security problems **privately**: open the repository's **Security** tab and choose **Report a vulnerability** (GitHub private
vulnerability reporting). Do not open a public issue or pull request for a vulnerability. You will get an answer as soon as possible; a fix
is released as a new dated tag and the changelog says what changed.

## Supported versions

Only the latest tagged release is supported. Sites pin an exact tag, so they update by taking the newest one.

## Scope and design notes

* Nothing in this repository is secret and nothing relies on the code being secret: there are no credentials, keys or site data in it
  (CI scans every push with gitleaks), and a site's own secrets live in its server `.env`, never here.
* Code that touches production data or the server is written to refuse by default (for example the performance probe only answers a
  request that carries its random token and refuses a production site unless told otherwise).
