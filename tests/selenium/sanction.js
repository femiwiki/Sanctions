'use strict';

const Page = require('wdio-mediawiki/Page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const SanctionsPage = require('./pageobjects/sanctions.page');
const Config = require('./config');
const Api = require('wdio-mediawiki/Api');

class Sanction {
  create(target = null, username = null, password = null) {
    target = target ? target : browser.config.mwUser;
	Config.verificationPeriod = 0;
    Config.verificationEdits = 0
    if (username && password) {
      UserLoginPage.login(username, password);
    } else {
      UserLoginPage.loginAdmin();
    }
    SanctionsPage.open();
    SanctionsPage.submit(target);

    const result = $('.sanction-execute-result a');
    result.waitForDisplayed();
    let uuid = result.getText();
    if (uuid.includes(':')) {
      uuid = uuid.split(':')[1];
    }
    return uuid.toLowerCase();
  }

  open(uuid) {
    new Page().openTitle('Topic:' + uuid);
  }
}

module.exports = new Sanction();
