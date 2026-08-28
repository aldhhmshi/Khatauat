import {readFile, writeFile, mkdir, watch} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {dirname, resolve} from 'node:path';

const root=resolve(import.meta.dirname,'..');
const files=[
  ['resources/assets/app.css','public/assets/css/app.css'],
  ['resources/assets/app.js','public/assets/js/app.js'],
];
const minify=(text,type)=>{
  if(type==='css') return text.replace(/\/\*[\s\S]*?\*\//g,'').replace(/\s+/g,' ').replace(/\s*([{}:;,])\s*/g,'$1').trim();
  return text.replace(/^\s*\/\/.*$/gm,'').replace(/\n{2,}/g,'\n').trim();
};
async function build(){
  for(const [src,dst] of files){
    const from=resolve(root,src),to=resolve(root,dst);await mkdir(dirname(to),{recursive:true});
    const text=await readFile(from,'utf8');await writeFile(to,minify(text,src.endsWith('.css')?'css':'js'));
    console.log(`built ${dst}`);
  }
}
await build();
if(process.argv.includes('--watch')){
  console.log('watching resources/assets…');
  const watcher=watch(resolve(root,'resources/assets'),{recursive:true});
  for await(const _ of watcher) await build();
}
