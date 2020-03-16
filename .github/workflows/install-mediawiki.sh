#!/bin/bash
set -euo pipefail; IFS=$'\n\t'

wget "https://github.com/wikimedia/mediawiki/archive/${MEDIAWIKI_VERSION}.zip" -q
unzip -q "${MEDIAWIKI_VERSION}.zip" && rm "${MEDIAWIKI_VERSION}.zip"
mv "mediawiki-${MEDIAWIKI_VERSION}" "${HOME}/mediawiki"
cp -r "$GITHUB_WORKSPACE" "${HOME}/mediawiki/extensions/"

composer install --prefer-dist --no-progress --no-suggest --no-interaction --dev --working-dir "${HOME}/mediawiki"
composer install --prefer-dist --no-progress --no-suggest --no-interaction --dev --working-dir "${HOME}/mediawiki/extensions/Sanctions"

php "${HOME}/mediawiki/maintenance/install.php" \
  --pass admin \
  --dbname testwiki \
  --dbuser root \
  --dbpass root \
  --dbport "${MYSQL_PORT}" \
  --scriptpath "/w" \
  testwiki admin
echo -e "\n\nrequire_once __DIR__ . '/includes/DevelopmentSettings.php';" >> "${HOME}/mediawiki/LocalSettings.php"
echo -e "wfLoadExtension( 'Sanctions' ); >>" "${HOME}/mediawiki/LocalSettings.php"
