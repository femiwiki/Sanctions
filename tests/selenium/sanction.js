'use strict';

const Api = require('wdio-mediawiki/Api');
const Page = require('wdio-mediawiki/Page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const Util = require('wdio-mediawiki/Util');
const SanctionsPage = require('./pageobjects/sanctions.page');
const Config = require('./config');

class Sanction {
  /**
   * @param string target
   * @param string username
   * @param string password
   * @returns string lower-cased uuid of the workflow for the sanction.
   */
  async create(
    target = browser.config.mwUser,
    username = browser.config.mwUser,
    password = browser.config.mwPwd
  ) {
    await UserLoginPage.login(username, password);

    const bot = await Api.bot(username, password);
    for (let count = 0; count < Config.VERIFICATION_EDITS; count++) {
      await bot.edit('Sanctions-dummy-edit', Util.getTestString());
    }

    await SanctionsPage.open();
    await SanctionsPage.waitUntilUserIsNotNew();
    await SanctionsPage.submit(target);

    const result = $('.sanction-execute-result a');
    await result.waitForDisplayed();
    let uuid = await result.getText();
    if (uuid.includes(':')) {
      uuid = uuid.split(':')[1];
    }
    return uuid.toLowerCase();
  }

  async open(uuid) {
    await new Page().openTitle('Topic:' + uuid);
  }

  async createVoters(bot, size = 3) {
    const voters = [];
    for (let count = 0; count < size; count++) {
      const username = Util.getTestString(`Sanction-voter${count}-`);
      const password = Util.getTestString();
      await Api.createAccount(bot, username, password);
      const voter = await Api.bot(username, password);
      voters.push(voter);
    }
    await browser.pause(Config.VERIFICATION_PERIOD * 1000);
    return voters;
  }
}

module.exports = new Sanction();
