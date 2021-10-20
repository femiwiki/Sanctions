'use strict';

const Api = require('wdio-mediawiki/Api');

class Config {
  set verificationPeriod(period) {
    browser.call(async () => {
      const bot = await Api.bot();
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        period
      );
    });
  }

  set verificationEdits(edits) {
    browser.call(async () => {
      const bot = await Api.bot();
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-edits',
        edits
      );
    });
  }

  set votingPeriod(period) {
    browser.call(async () => {
      const bot = await Api.bot();
      await bot.edit('MediaWiki:sanctions-voting-period', period);
    });
  }

  set discussionPage(name) {
    browser.call(async () => {
      const bot = await Api.bot();
      await bot.edit('MediaWiki:sanctions-discussion-page-name', name);
    });
  }
}

module.exports = new Config();
