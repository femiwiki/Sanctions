'use strict';

const Api = require('wdio-mediawiki/Api');
const SECONDS_IN_DAY = 86400;

/** In seconds */
const VERIFICATION_PERIOD = 10;
const VERIFICATION_EDITS = 0;
/** In seconds */
const VOTING_PERIOD = 20;

class Config {
  /** In seconds */
  static get VERIFICATION_PERIOD() {
    return VERIFICATION_PERIOD;
  }
  static get VERIFICATION_EDITS() {
    return VERIFICATION_EDITS;
  }
  /** In seconds */
  static get VOTING_PERIOD() {
    return VOTING_PERIOD;
  }

  async setup() {
    const bot = await Api.bot();
    try {
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        VERIFICATION_PERIOD / SECONDS_IN_DAY
      );
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-edits',
        VERIFICATION_EDITS
      );
      await bot.edit(
        'MediaWiki:sanctions-voting-period',
        VOTING_PERIOD / SECONDS_IN_DAY
      );
      await bot.edit('MediaWiki:sanctions-autoblock', '0');
    } catch (e) {
      // Ignore Error: edit-already-exists
    }
  }
}

module.exports = Config;
