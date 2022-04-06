'use strict';
const Page = require('wdio-mediawiki/Page');

class SanctionsPage extends Page {
  get reasonsDisabledParticipation() {
    return $('.sanctions-reasons-disabled-participation');
  }
  get target() {
    return $('#sanctions-target');
  }
  get forInsultingName() {
    return $('#forInsultingName');
  }
  get submitButton() {
    return $('#sanctions-submit-button');
  }
  get sanctions() {
    return $('.sanctions');
  }
  get sanctionLink() {
    return $('.sanction a.sanction-type');
  }

  async open(subpage) {
    await super.openTitle('Special:Sanctions/' + subpage, { uselang: 'qqx' });
  }

  async waitUntilUserIsNotNew() {
    let text;
    do {
      text = await this.reasonsDisabledParticipation.getText();

      // Wait
      await browser.pause(3000);
      browser.refresh();
    } while (/sanctions-reason-unsatisfying-verification-period/.test(text));
  }

  async submit(target, isForInsultingName = false) {
    await this.target.waitForDisplayed();
    await this.target.setValue(target);
    if (isForInsultingName) {
      await this.forInsultingName.click();
    }
    await this.submitButton.click();
  }
}
module.exports = new SanctionsPage();
