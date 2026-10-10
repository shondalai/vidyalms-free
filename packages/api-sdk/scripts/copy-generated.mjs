// tsc does not copy .d.ts inputs to outDir; consumers of dist need the schema next to index.d.ts.
import { mkdir, copyFile } from "node:fs/promises";
import { URL } from "node:url";

const here = new URL(".", import.meta.url);
await mkdir(new URL("../dist/generated/", here), { recursive: true });
await copyFile(
  new URL("../src/generated/schema.d.ts", here),
  new URL("../dist/generated/schema.d.ts", here),
);
