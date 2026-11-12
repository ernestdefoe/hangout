/*
 * The world, one chunk fetched the first time someone opens the Hangout: the
 * canvas renderer, live state, panels and command bar. forum.js stays small.
 */

declare const flarum: any;

/** Core's page frame, loaded through the registry before the page draws (it is lazy in Flarum 2). */
export let PageStructure: any;

export async function loadCore(): Promise<void> {
  if (PageStructure) return;
  const structure = await flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure');
  PageStructure = structure.default || structure;
}

export { default as HangoutPage } from './components/HangoutPage';
