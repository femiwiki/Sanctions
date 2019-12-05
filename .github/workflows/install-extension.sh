#!/bin/bash
set -euo pipefail; IFS=$'\n\t'

wget "https://github.com/wikimedia/mediawiki-extensions-$1/archive/${MEDIAWIKI_VERSION}.zip" -q
unzip -q "${MEDIAWIKI_VERSION}.zip" && rm "${MEDIAWIKI_VERSION}.zip"
mv "mediawiki-extensions-$1-${MEDIAWIKI_VERSION}" "${HOME}/mediawiki/extensions/$1"
