import subprocess,time,urllib.request,urllib.parse,os,sys,signal
P=int(os.environ.get('PORT','8123')); BASE=f'http://127.0.0.1:{P}'
DUMMY='XXXX.DUMMY.TOKEN.XXXX'
PASS_SEC='1x0000000000000000000000000000000AA'
FAIL_SEC='2x0000000000000000000000000000000AA'
ok_n=fail_n=0
def chk(c,name,extra=''):
    global ok_n,fail_n
    if c: ok_n+=1; print(f'  PASS  {name}')
    else: fail_n+=1; print(f'  FAIL  {name}'+(f'  -> {extra}' if extra else ''))
def serve(secret,failmode='closed'):
    env=dict(os.environ,CF_SECRET=secret,FAIL_MODE=failmode,STUB_DIR=os.path.abspath('stubs')+'/')
    p=subprocess.Popen([os.environ.get('PHP_BIN','php'),'-S',f'127.0.0.1:{P}','-t','www'],env=env,
                       stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(60):
        try: urllib.request.urlopen(BASE+'/login.php',timeout=2); return p
        except Exception: time.sleep(0.25)
    p.kill(); raise RuntimeError('server not up')
def req(path,data=None,timeout=20):
    body=urllib.parse.urlencode(data).encode() if data else None
    r=urllib.request.Request(BASE+path,data=body)
    try:
        resp=urllib.request.urlopen(r,timeout=timeout)
        t=resp.read().decode('utf8','replace')
        assert 'Fatal error' not in t and 'Warning:' not in t and 'Parse error' not in t, 'PHP-Fehler: '+t[:400]
        return resp.status,dict(resp.headers),t
    except urllib.error.HTTPError as e:
        t=e.read().decode('utf8','replace')
        assert 'Fatal error' not in t and 'Parse error' not in t, 'PHP-Fehler: '+t[:400]
        return e.code,dict(e.headers),t

print('\n== I1 GET Client-Login: Injektion + CSP ==')
srv=serve(PASS_SEC)
try:
    st,h,b=req('/login.php')
    chk(st==200,'GET liefert 200',st)
    chk(b.count('cf-turnstile')>=1,'Widget-Container injiziert')
    import re as _re
    _tags=_re.findall(r'<script[^>]+src="[^"]*api\.js', b)
    chk(len(_tags)==1,'genau ein api.js-Script-Tag',len(_tags))
    chk(b.count('class="cf-turnstile"')==1,'genau ein Widget-Container',b.count('class="cf-turnstile"'))
    li=b.find('action="login.php"'); si=b.find('action="/search.php"')
    wi=b.find('class="cf-turnstile"')
    chk(li<wi,'Widget steht im Login-Formular, nicht im Suchformular',f'login@{li} widget@{wi} search@{si}')
    chk(b.find('</form>',wi)>wi,'Widget vor dem schließenden </form>')
    csp=h.get('Content-Security-Policy','')
    chk('challenges.cloudflare.com' in csp,'CSP-Header enthält Cloudflare-Origin',csp)
    chk("script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com" in csp,'genau in script-src',csp)
    chk('frame-src' not in csp,'kein frame-src hinzugefügt')
    chk(csp.count('Content-Security-Policy')==0 and 'object-src' in csp,'übrige Direktiven erhalten',csp)

    print('\n== I2 POST ohne Token -> blockiert ==')
    st,h,b=req('/login.php',{'luser':'a','lpasswd':'b'})
    chk(st==403,'HTTP 403',st)
    chk('MARKER-AUTH-REACHED' not in b,'Auth-Pfad wurde NICHT erreicht')
    chk('Sicherheitsprüfung' in b,'Fehlerseite ausgeliefert')

    print('\n== I3 POST mit gültigem Token -> durchgelassen ==')
    st,h,b=req('/login.php',{'luser':'a','lpasswd':'b','cf-turnstile-response':DUMMY})
    chk(st==200,'HTTP 200',st)
    chk('MARKER-AUTH-REACHED' in b,'Auth-Pfad erreicht')

    print('\n== I4 Nicht-Login-POST (Passwort vergessen) ==')
    st,h,b=req('/login.php',{'email':'a@b.de'})
    chk(st==200 and 'MARKER-AUTH-REACHED' in b,'wird nicht fälschlich blockiert',st)

    print('\n== I5 Staff-Login (scp/login.php) ==')
    st,h,b=req('/scp/login.php')
    chk('cf-turnstile' in b,'Widget auch auf scp/login.php')
    st,h,b=req('/scp/login.php',{'username':'a','passwd':'b'})
    chk(st==403,'Staff-POST ohne Token blockiert',st)
finally:
    srv.terminate(); srv.wait()

print('\n== I6 Ungültiges Token (always-fail Secret) ==')
srv=serve(FAIL_SEC)
try:
    st,h,b=req('/login.php',{'luser':'a','lpasswd':'b','cf-turnstile-response':DUMMY})
    chk(st==403,'HTTP 403',st)
    chk('MARKER-AUTH-REACHED' not in b,'Auth-Pfad nicht erreicht')
    for code in ['invalid-input-response','timeout-or-duplicate','bad-request','curl']:
        chk(code not in b,f'kein Leak von "{code}" an den Client')
    chk('/sessions/' not in b and '.php' not in b.replace('login.php',''),'kein Pfad-Leak in der Fehlerseite')
finally:
    srv.terminate(); srv.wait()

print(f'\n{"-"*52}\nIntegration  PASS: {ok_n}  FAIL: {fail_n}')
sys.exit(1 if fail_n else 0)
