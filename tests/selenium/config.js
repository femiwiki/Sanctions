'use strict';

const Api = require('wdio-mediawiki/Api');

class Config {
  setVerifications(period, edits) {
    browser.call(async () => {
      const bot = await Api.bot();
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-period',
        '' + period
      );
      await bot.edit(
        'MediaWiki:sanctions-voting-right-verification-edits',
        '' + edits
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
