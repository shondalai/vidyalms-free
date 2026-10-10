// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { createLearnerAssetLoader } from './assets';

const parse = (html: string) => new DOMParser().parseFromString(html, 'text/html');
afterEach(() => { document.head.innerHTML = ''; document.body.innerHTML = ''; });

describe('Learner mount asset loading', () => {
    it('continues navigation with already loaded assets without executing them again', async () => {
        document.head.innerHTML = '<script data-vy-spa-asset src="https://academy.test/assets/player.js?v=1"></script>';
        const loader = createLearnerAssetLoader();
        await loader.scripts(parse('<script data-vy-spa-asset src="./player.js?v=1"></script><script src="/host.js"></script>'), 'https://academy.test/assets/');
        expect(document.querySelectorAll('script')).toHaveLength(1);
    });
    it('uses native navigation for a new bundle instead of executing authored markup', async () => {
        document.head.innerHTML = '<script nonce="trusted-csp-nonce"></script>';
        const page = parse('<main data-vy-learner-shell><script data-vy-spa-asset src="https://attacker.test/payload.js"></script></main>');
        await expect(createLearnerAssetLoader().scripts(page, 'https://academy.test/')).rejects.toThrow('full page load');
        expect(document.querySelector('script[src]')).toBeNull();
    });
    it('does not load new styles from a fetched document', async () => {
        await expect(createLearnerAssetLoader().styles(parse('<link data-vy-spa-asset rel="stylesheet" href="/new.css">'), 'https://academy.test/')).rejects.toThrow('full page load');
        expect(document.querySelector('link')).toBeNull();
    });
    it('refuses to mix a newly deployed entry with the existing application runtime', async () => {
        document.head.innerHTML = '<script src="https://academy.test/player.js?v=1"></script>';
        await expect(createLearnerAssetLoader().scripts(parse('<script data-vy-spa-asset src="/player.js?v=2"></script>'), 'https://academy.test/')).rejects.toThrow('updated');
    });
});
