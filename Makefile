# Usage: make release VERSION=1.2.3 (pushes the current branch and release tag to origin).
.PHONY: release
release: export SWMG_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh
