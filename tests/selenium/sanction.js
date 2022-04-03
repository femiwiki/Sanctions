'use strict';

const Page = require('wdio-mediawiki/Page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const SanctionsPage = require('./pageobjects/sanctions.page');
const Config = require('./config');
const Api = require('wdio-mediawiki/Api');

class Sanction {
  async create(target = null, username = null, password = null) {
    target = target ? target : browser.config.mwUser;
    await Config.setVerificationPeriod(0);
    await Config.setVerificationEdits(0);
    if (username && password) {
      await UserLoginPage.login(username, password);
    } else {
      await UserLoginPage.loginAdmin();
    }
    await SanctionsPage.open();
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
}

module.exports = new Sanction();
