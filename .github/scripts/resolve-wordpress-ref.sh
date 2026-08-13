#!/usr/bin/env bash

set -euo pipefail

WP_VERSION="${1:?Usage: resolve-wordpress-ref.sh <version>}"
WORDPRESS_REPOSITORY="https://github.com/WordPress/WordPress"
LOOKUP_TIMEOUT="30s"
GIT_COMMAND="${GIT_COMMAND:-git}"

if [ "${WP_VERSION}" = "6.4" ]; then
	printf '%s\n' "${WP_VERSION}"
	exit 0
fi

if ! TAG_RESULT=$(timeout "${LOOKUP_TIMEOUT}" "${GIT_COMMAND}" ls-remote --tags "${WORDPRESS_REPOSITORY}" "refs/tags/${WP_VERSION}"); then
	echo "::error::Unable to query the WordPress ${WP_VERSION} tag" >&2
	exit 1
fi
if [ -n "${TAG_RESULT}" ]; then
	printf '%s\n' "${WP_VERSION}"
	exit 0
fi

WP_BRANCH="${WP_VERSION}-branch"
if ! BRANCH_RESULT=$(timeout "${LOOKUP_TIMEOUT}" "${GIT_COMMAND}" ls-remote --heads "${WORDPRESS_REPOSITORY}" "refs/heads/${WP_BRANCH}"); then
	echo "::error::Unable to query the WordPress ${WP_BRANCH} release branch" >&2
	exit 1
fi
if [ -n "${BRANCH_RESULT}" ]; then
	echo "::notice::WordPress tag ${WP_VERSION} not found — using release branch ${WP_BRANCH}" >&2
	printf '%s\n' "${WP_BRANCH}"
	exit 0
fi

echo "::warning::WordPress tag and release branch for ${WP_VERSION} not found — falling back to trunk" >&2
printf 'trunk\n'
