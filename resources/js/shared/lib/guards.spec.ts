import { describe, expect, it } from 'vitest';
import {
  asNullableString,
  asNullableText,
  asStringList,
  isPlainRecord,
  isRecord,
  isSelectOption,
  labelledRefOf,
  optionLabelOf,
  optionsOf,
} from './guards';

describe('isRecord', () => {
  it('accepts every non-null object, an array included', () => {
    expect(isRecord({ id: 1 })).toBe(true);
    expect(isRecord([1, 2])).toBe(true);
  });

  it('refuses null and primitives', () => {
    expect(isRecord(null)).toBe(false);
    expect(isRecord('text')).toBe(false);
    expect(isRecord(3)).toBe(false);
  });
});

describe('isPlainRecord', () => {
  it('accepts a keyed object', () => {
    expect(isPlainRecord({ id: 1 })).toBe(true);
  });

  it('refuses an array, null and primitives', () => {
    expect(isPlainRecord([1, 2])).toBe(false);
    expect(isPlainRecord(null)).toBe(false);
    expect(isPlainRecord('text')).toBe(false);
  });
});

describe('isSelectOption', () => {
  it('accepts an option with a string value and label', () => {
    expect(isSelectOption({ value: 'a', label: 'A' })).toBe(true);
  });

  it('refuses an option without a string label', () => {
    expect(isSelectOption({ value: 'a', label: 1 })).toBe(false);
    expect(isSelectOption(null)).toBe(false);
  });
});

describe('the value readers', () => {
  it('keep only strings of a list', () => {
    expect(asStringList(['a', 1, 'b'])).toEqual(['a', 'b']);
    expect(asStringList('a')).toEqual([]);
  });

  it('read a nullable string', () => {
    expect(asNullableString('a')).toBe('a');
    expect(asNullableString(1)).toBeNull();
  });

  it('turn raw entries into options', () => {
    expect(optionsOf([{ value: 'a', label: 'A' }])).toEqual([{ value: 'a', label: 'A' }]);
  });
});

describe('asNullableText', () => {
  it('turns any present value into text', () => {
    expect(asNullableText('a')).toBe('a');
    expect(asNullableText(3)).toBe('3');
  });

  it('answers null for a missing value', () => {
    expect(asNullableText(null)).toBeNull();
    expect(asNullableText(undefined)).toBeNull();
  });
});

describe('labelledRefOf', () => {
  it('reads the id and label of a reference', () => {
    expect(labelledRefOf({ id: 7, label: 'Anna', extra: true })).toEqual({
      id: '7',
      label: 'Anna',
    });
  });

  it('fills a missing id or label with an empty string', () => {
    expect(labelledRefOf({})).toEqual({ id: '', label: '' });
  });

  it('answers null for no reference', () => {
    expect(labelledRefOf(null)).toBeNull();
    expect(labelledRefOf('Anna')).toBeNull();
  });
});

describe('optionLabelOf', () => {
  const options = [{ value: 'user-1', label: 'Anna' }];

  it('names the chosen option', () => {
    expect(optionLabelOf(options, 'user-1')).toBe('Anna');
  });

  it('falls back to the value itself', () => {
    expect(optionLabelOf(options, 'user-2')).toBe('user-2');
  });
});
