'use strict';

const Api = require('wdio-mediawiki/Api');

class Config {
  async setVerificationPeriod(period) {
    const bot = await Api.bot();
    await bot.edit(
      'MediaWiki:sanctions-voting-right-verification-period',
      period
    );
  }

  async setVerificationEdits(edits) {
    const bot = await Api.bot();
    await bot.edit(
      'MediaWiki:sanctions-voting-right-verification-edits',
      edits
    );
  }

  async setVotingPeriod(period) {
    const bot = await Api.bot();
    await bot.edit('MediaWiki:sanctions-voting-period', period);
  }

  async setDiscussionPage(name) {
    const bot = await Api.bot();
    await bot.edit('MediaWiki:sanctions-discussion-page-name', name);
  }
}

module.exports = new Config();
