// Credit: https://usehooks-ts.com/
import { useState } from 'react';
import { copyToClipboard } from '@/lib/clipboard';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        const isCopied = await copyToClipboard(text);
        setCopiedText(isCopied ? text : null);

        return isCopied;
    };

    return [copiedText, copy];
}
