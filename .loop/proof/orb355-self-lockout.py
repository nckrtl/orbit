#!/usr/bin/env python3
"""Record an actual consumer revoking its own Gateway access on disposable Incus."""
import argparse,hashlib,json,os,pathlib,shlex,signal,subprocess,sys,termios
if len(sys.argv)>1 and sys.argv[1]=='--child':
 before=termios.tcgetattr(0)
 with open(sys.argv[3],'wb') as err:
  child=subprocess.Popen(sys.argv[4:],stderr=err)
  signal.signal(signal.SIGINT,signal.SIG_IGN);code=child.wait()
 after=termios.tcgetattr(0)
 pathlib.Path(sys.argv[2]).write_text(json.dumps({'equal':before==after,'before':repr(before),'after':repr(after),'exit':code}))
 sys.exit(code)
p=argparse.ArgumentParser();p.add_argument('--candidate',required=True);p.add_argument('--state',type=pathlib.Path,required=True);p.add_argument('--consumer',type=int);p.add_argument('--gateway',type=int);p.add_argument('--json-mode',action='store_true');p.add_argument('--visible',action='store_true');p.add_argument('--discovery-manifest',type=pathlib.Path);a=p.parse_args()
os.umask(0o077);a.state.mkdir(parents=True,exist_ok=False)
source=pathlib.Path('/home/orbit/orbit')
if a.discovery_manifest:
 m=json.loads(a.discovery_manifest.read_text());assert m['candidate']==a.candidate
 for e in m['files']:
  f=source/e['path'];data=os.readlink(f).encode() if e['symlink'] else f.read_bytes();assert hashlib.sha256(data).hexdigest()==e['sha256'],e['path']
else:
 assert subprocess.check_output(['git','-C',str(source),'rev-parse','HEAD'],text=True).strip()==a.candidate
 assert not subprocess.check_output(['git','-C',str(source),'status','--porcelain','--untracked-files=no'],text=True).strip()
env=dict(os.environ,PAO_DISABLE='1',TERM='xterm-256color',LC_ALL='C.UTF-8')
for k in ['NO_COLOR','FORCE_COLOR','CLICOLOR','COLUMNS','LINES']:env.pop(k,None)
cli=['php',str(source/'apps/cli/orbit')]
def read(argv,expected=0):
 r=subprocess.run(cli+argv+['--json'],cwd=source,env=env,input=b'',capture_output=True,timeout=90)
 assert r.returncode==expected and r.stderr==b'' and b'\x1b' not in r.stdout,(argv,r.returncode,r.stdout,r.stderr)
 return json.loads(r.stdout)
nodes=read(['node:list'])['nodes']
a.consumer=a.consumer or next(n['id'] for n in nodes if n['name']=='app-dev')
a.gateway=a.gateway or next(n['id'] for n in nodes if n['name']=='gateway')
before=read(['node:show',str(a.consumer)])
gateway=read(['node:show',str(a.gateway)])
assert any(n['id']==a.gateway for n in before['access']['can_access'])
(a.state/'before.json').write_text(json.dumps({'consumer':before,'gateway':gateway},indent=2))
label='self-lockout-json' if a.json_mode else 'self-lockout-human'
command=cli+['node:access:remove',str(a.consumer),str(a.gateway),'--force','--json' if a.json_mode else '--ansi']
recorder=source/'.agents/skills/verifying-cli-output/scripts'
capture=[sys.executable,str(recorder/'capture.py'),'--output-dir',str(a.state/'capture'),'--candidate',a.candidate,'--label',label,'--columns','100' if a.json_mode else '24','--rows','60','--timeout','120','--idle-timeout','60']
if not a.visible:capture.append('--no-live')
capture+=['--',sys.executable,str(pathlib.Path(__file__).resolve()),'--child',str(a.state/'terminal.json'),str(a.state/'stderr.bin'),*command]
print('$ '+shlex.join(command),flush=True)
r=subprocess.run(capture,cwd=source,env=env,timeout=135);assert r.returncode==0
assert (a.state/'stderr.bin').read_bytes()==b''
assert json.loads((a.state/'terminal.json').read_text())['equal']
frames=[json.loads(x) for x in (a.state/'capture/frames.jsonl').read_text().splitlines()]
assert not frames[-1]['cursor']['hidden']
raw=(a.state/'capture/raw.bin').read_bytes()
if a.json_mode:
 assert b'\x1b' not in raw
 value=json.loads(raw);expected={'consumer_node':{'id':a.consumer,'name':before['name']},'serving_node':{'id':a.gateway,'name':gateway['name']},'already_absent':False,'self_lockout':True}
 assert {k:v for k,v in value.items() if k!='request_id'}==expected,value
 assert isinstance(value['request_id'],str)
else:
 compact=''.join(''.join(frames[-1]['lines']).split())
 assert 'Warning:ThisnodenolongerhasGatewayaccess.' in compact,compact
 assert f"Accessfrom[{before['name']}](#{a.consumer})to[{gateway['name']}](#{a.gateway})removed." in compact
expect={'candidate':a.candidate,'label':label,'exit_code':0,'contains':['request_id'] if a.json_mode else ['Warning:'],'max_first_output_seconds':3}
(a.state/'expectation.json').write_text(json.dumps(expect))
v=subprocess.run([sys.executable,str(recorder/'verify.py'),'--capture',str(a.state/'capture'),'--expect',str(a.state/'expectation.json')],capture_output=True,text=True)
(a.state/'verify.json').write_text(v.stdout);assert v.returncode==0,(v.stdout,v.stderr)
denied=read(['node:show',str(a.gateway)],expected=1)
assert denied['error']['code']=='node_access.required',denied
(a.state/'denied.json').write_text(json.dumps(denied))
(a.state/'result.json').write_text(json.dumps({'passed':True,'candidate':a.candidate,'command':command,'consumer':a.consumer,'gateway':a.gateway,'self_lockout':True,'denied_after':denied['error']['code'],'recovery_required':True}))
print(json.dumps({'passed':True,'self_lockout':True,'recovery_required':True,'root':str(a.state)}),flush=True)
