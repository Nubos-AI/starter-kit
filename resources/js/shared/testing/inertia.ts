export const headStub = {
  props: ['title'],
  template: '<head-stub :title="title"><slot /></head-stub>',
};

export const linkStub = {
  props: ['href', 'only'],
  template: '<a data-inertia-link :href="typeof href === \'string\' ? href : href?.url"><slot /></a>',
};

export const inertiaStubs = { Head: headStub, Link: linkStub };
