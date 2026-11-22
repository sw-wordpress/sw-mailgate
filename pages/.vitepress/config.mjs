// No VitePress import: the toolchain is installed only in a temporary CI directory.
export default {
  lang: 'en-US',
  title: 'SW Mailgate',
  description: 'Send WordPress mail reliably with SMTP, Mailgun, Gmail and Microsoft 365.',
  base: '/sw-mailgate/',
  srcExclude: ['README.md'],
  cleanUrls: false,
  themeConfig: {
    nav: [
      { text: 'Getting Started', link: '/getting-started' },
      { text: 'Configuration', link: '/configuration' }
    ],
    sidebar: [
      { text: 'User guide', items: [
        { text: 'Overview', link: '/' },
        { text: 'Getting Started', link: '/getting-started' },
        { text: 'Configuration', link: '/configuration' }
      ] }
    ],
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/sw-wordpress/sw-mailgate' }],
    editLink: {
      pattern: 'https://github.com/sw-wordpress/sw-mailgate/edit/master/pages/:path',
      text: 'Edit this page on GitHub'
    },
    footer: { message: 'Open source by Stefan Warnat. GPL-2.0-or-later.' }
  }
};
