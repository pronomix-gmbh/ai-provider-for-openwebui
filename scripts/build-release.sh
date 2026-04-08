#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR_NAME="${PLUGIN_DIR_NAME:-ai-provider-for-open-webui}"
DIST_DIR="${ROOT_DIR}/dist"
TMP_DIR="$(mktemp -d)"
STAGE_DIR="${TMP_DIR}/${PLUGIN_DIR_NAME}"

cleanup() {
	rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

VERSION="$(
	awk -F': *' '/^[[:space:]]*\*[[:space:]]+Version:/ { print $2; exit }' "${ROOT_DIR}/ai-provider-for-open-webui.php" | xargs
)"

if [[ -z "${VERSION}" ]]; then
	echo "Keine Version im Plugin-Header gefunden." >&2
	exit 1
fi

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

rsync -a \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude '.gitignore' \
	--exclude 'vendor/' \
	--exclude 'tests/' \
	--exclude 'dist/' \
	--exclude 'scripts/' \
	--exclude 'phpcs.xml.dist' \
	--exclude 'phpunit.xml.dist' \
	--exclude '.phpunit.result.cache' \
	--exclude '.DS_Store' \
	--exclude '.idea/' \
	--exclude '.vscode/' \
	"${ROOT_DIR}/" "${STAGE_DIR}/"

(
	cd "${STAGE_DIR}"
	composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
	rm -f composer.lock
)

ZIP_PATH="${DIST_DIR}/${PLUGIN_DIR_NAME}-${VERSION}.zip"
(
	cd "${TMP_DIR}"
	zip -rq "${ZIP_PATH}" "${PLUGIN_DIR_NAME}"
)

echo "Build erstellt: ${ZIP_PATH}"
