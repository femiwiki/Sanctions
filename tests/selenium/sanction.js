'use strict';

const Page = require('wdio-mediawiki/Page');
const UserLoginPage = require('wdio-mediawiki/LoginPage');
const SanctionsPage = require('./pageobjects/sanctions.page');
const Config = require('./config');

class Sanction {
  create(target) {
    Config.setVerifications(0, 0);
    UserLoginPage.loginAdmin();
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
