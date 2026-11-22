# Maintaining the user guide

Edit the Markdown files in this directory. `pages/.vitepress/config.mjs` defines
navigation, local search and the GitHub project path `/sw-mailgate/`.
The toolchain, npm manifest, dependencies, cache and generated site are not committed
and are not included in plugin release ZIPs. The root npm test dependencies are unchanged.

## Deployment

In the repository's **Settings → Pages → Build and deployment**, select
**GitHub Actions** as the source (one-time administrator setup). The Pages environment
must allow deployments from `master`. No PAT or additional deployment secret is needed.

`.github/workflows/pages.yml` installs the pinned VitePress version into
`runner.temp`, copies `pages/` there, builds and uploads a Pages artifact. Pull
requests validate the same build (including VitePress's internal-link checks)
without deployment permissions. Pushes to `master` deploy through the official
Pages action; `workflow_dispatch` can redeploy `master`. No `gh-pages` branch is needed.

Only documentation and workflow changes trigger automatic builds. The maintainer
README is excluded from the generated site. Change the workflow pin deliberately
when upgrading VitePress; transitive dependencies are resolved during installation.

## Optional local preview (isolated toolchain)

From the repository root, with Node.js 22 and npm installed:

```sh
DOCS_BUILD="$(mktemp -d)"
cp -R pages "$DOCS_BUILD/pages"
npm install --prefix "$DOCS_BUILD" --no-save --package-lock=false --ignore-scripts --no-audit --no-fund vitepress@1.6.4
"$DOCS_BUILD/node_modules/.bin/vitepress" build "$DOCS_BUILD/pages"
"$DOCS_BUILD/node_modules/.bin/vitepress" preview "$DOCS_BUILD/pages"
# After stopping preview, remove the temporary directory:
rm -rf "$DOCS_BUILD"
```

This keeps npm metadata, dependencies and generated output outside the checkout.
Copy the sources again after editing. Do not enable `ignoreDeadLinks` to hide broken links.

## Screenshots

Only add captures from an actual disposable WordPress installation, with synthetic
example data and no real credentials, tokens, recipients or private site details.
Store them in `pages/public/screenshots/` and reference them with descriptive alt
text, e.g. `![SMTP connection editor](/screenshots/smtp-connection.png)`.
Do not use recreated HTML mockups as product screenshots.
