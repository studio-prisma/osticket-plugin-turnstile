import subprocess,time,os,re,sys,urllib.request,urllib.parse,http.cookiejar

# Staff-Zugangsdaten der Testinstanz — nie hart im Repo.
STAFF_USER = os.environ.get('OST_STAFF_USER', 'admin')
STAFF_PASS = os.environ.get('OST_STAFF_PASS', '')
if not STAFF_PASS:
    sys.exit('OST_STAFF_PASS ist nicht gesetzt — siehe tests/README.md')
HOME=os.path.expanduser('~'); OST=f'{HOME}/w/ost/upload'; PHP=f'{HOME}/w/php83/php'
PORT=8090; BASE=f'http://127.0.0.1:{PORT}'
MYSQL=[f'{HOME}/w/mdb/usr/bin/mysql',f'--socket={HOME}/w/run/my.sock','-u','root','osticket']
ENV=dict(os.environ,LD_LIBRARY_PATH=f'{HOME}/w/mdb/usr/lib/x86_64-linux-gnu')
DUMMY='XXXX.DUMMY.TOKEN.XXXX'
PASS_SEC='1x0000000000000000000000000000000AA'; FAIL_SEC='2x0000000000000000000000000000000AA'
P=F=0
def chk(c,n,x=''):
    global P,F
    if c: P+=1; print(f'  PASS  {n}')
    else: F+=1; print(f'  FAIL  {n}'+(f'  -> {x}' if x else ''))
def sql(q): subprocess.run(MYSQL,input=q.encode(),env=ENV,capture_output=True)
def secret(s): sql(f"UPDATE ost_config SET value='{s}' WHERE namespace='plugin.1.instance.1' AND `key`='cf_secret_key';")
def cfg(k,v): sql(f"UPDATE ost_config SET value='{v}' WHERE namespace='plugin.1.instance.1' AND `key`='{k}';")
def count_tickets():
    r=subprocess.run(MYSQL+['-N','-B','-e','SELECT COUNT(*) FROM ost_ticket'],env=ENV,capture_output=True)
    return r.stdout.decode().strip() or '0'

srv=subprocess.Popen([PHP,'-S',f'127.0.0.1:{PORT}','-t',OST],cwd=OST,
                     stdout=open(f'{HOME}/w/run/e2e.log','w'),stderr=subprocess.STDOUT)
cj=http.cookiejar.CookieJar(); op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
def go(path,data=None,hdrs=None):
    b=urllib.parse.urlencode(data).encode() if data else None
    r=urllib.request.Request(BASE+path,data=b,headers=hdrs or {})
    try: x=op.open(r,timeout=60); return x.status,dict(x.headers),x.read().decode('utf8','replace')
    except urllib.error.HTTPError as e: return e.code,dict(e.headers),e.read().decode('utf8','replace')
for _ in range(80):
    try: go('/open.php'); break
    except Exception: time.sleep(0.4)

try:
    print('\n== A  open.php: echtes osTicket-Rendering ==')
    st,h,b=go('/open.php')
    chk(st==200,'GET open.php 200',st)
    chk('Fatal error' not in b and 'Parse error' not in b,'kein PHP-Fehler',b[:200])
    # Befund: osTicket rendert die Ticket-Detail-Felder NICHT im initialen HTML;
    # sie kommen erst per ajax.php nach Wahl des Help Topics (siehe B).
    chk('Issue Summary' not in b,'osTicket liefert die Ticketfelder erwartungsgemäß noch nicht mit')
    csp=h.get('Content-Security-Policy','')
    chk('challenges.cloudflare.com' in csp,'CSP von osTicket wurde erweitert',csp[:120])
    chk("script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com" in csp,'genau in script-src',csp[:160])
    m=re.search(r'name="__CSRFToken__"\s+value="([^"]+)"',b) or re.search(r'value="([^"]+)"[^>]*name="__CSRFToken__"',b)
    csrf=m.group(1) if m else None
    chk(csrf is not None,'CSRF-Token gefunden')

    print('\n== B  AJAX-Nachladen beim Help-Topic-Wechsel ==')
    st,h,b2=go('/ajax.php/form/help-topic/1',hdrs={'X-Requested-With':'XMLHttpRequest',
               'X-CSRFToken':csrf or '','Referer':BASE+'/open.php'})
    chk(st==200,'AJAX-Form 200',st)
    chk('Fatal error' not in b2,'kein PHP-Fehler im AJAX-Teil',b2[:200])
    chk('cf-turnstile' in b2,'Widget im AJAX-nachgeladenen Ticketformular')
    import json as _j0
    try: _h0=_j0.loads(b2).get('html','')
    except Exception: _h0=b2
    # Das Fragment ist JSON: json_encode escaped Umlaute zu \u00fc.
    # Geprüft wird deshalb der dekodierte HTML-Teil.
    chk('Sicherheitsprüfung' in _h0,'Feld-Label im Fragment (nach JSON-Decode)')
    chk('\\u00fc' in b2,'Umlaut korrekt JSON-escaped ausgeliefert')
    chk('ostTurnstileRender' in b2,'Re-Render-Funktion im Fragment (Auto-Modus würde hier scheitern)')
    chk('render=explicit' in b2,'expliziter Render-Modus im Fragment')
    chk('setInterval' in b2,'Poller-Sicherheitsnetz im Fragment')
    import json as _j
    try: _html=_j.loads(b2).get('html','')
    except Exception: _html=b2
    chk(len(re.findall(r'<script[^>]+src="[^"]*api\.js',_html))==1,'genau ein api.js-Script-Tag',
        len(re.findall(r'<script[^>]+src="[^"]*api\.js',_html)))
    chk(_html.count('class="cf-turnstile"')==1,'genau EIN Widget-Container',_html.count('class="cf-turnstile"'))

    print('\n== C  open.php POST: Ticket anlegen ==')
    ERR_MISSING='Bitte bestätigen Sie die Sicherheitsprüfung.'
    ERR_FAILED='Die Sicherheitsprüfung war nicht erfolgreich'
    ERR_EXPIRED='Die Sicherheitsprüfung ist abgelaufen'
    base_post={'__CSRFToken__':csrf or '','a':'open','topicId':'1','name':'Test Nutzer',
               'email':'kunde@example.com','subject':'E2E Turnstile',
               'message':'Testinhalt aus dem E2E-Lauf.'}
    def fresh():
        _,_,p=go('/open.php')
        m=re.search(r'name="__CSRFToken__"\s+value="([^"]+)"',p)
        return dict(base_post,__CSRFToken__=m.group(1) if m else '')
    secret(PASS_SEC)
    st,h,b4=go('/open.php',fresh())
    chk(ERR_MISSING in b4,'ohne Token: exakte Plugin-Fehlermeldung im Formular')
    chk('Support Ticket Request Created' not in b4 and 'ticket-thread' not in b4,'ohne Token: kein Ticket angelegt')
    secret(FAIL_SEC)
    st,h,b5=go('/open.php',dict(fresh(),**{'cf-turnstile-response':DUMMY}))
    chk(ERR_FAILED in b5 or ERR_EXPIRED in b5,'ungültiges Token: abgelehnt')
    for leak in ['invalid-input-response','timeout-or-duplicate','curl(','challenges.cloudflare.com/turnstile/v0/siteverify']:
        chk(leak not in b5,f'kein Leak von "{leak}"')
    secret(PASS_SEC)
    before=int(count_tickets())
    st,h,b3=go('/open.php',dict(fresh(),**{'cf-turnstile-response':DUMMY}))
    after=int(count_tickets())
    chk(ERR_MISSING not in b3 and ERR_FAILED not in b3,'gültiges Token: keine Turnstile-Fehlermeldung')
    chk(after==before+1,'gültiges Token: Ticket wurde tatsächlich angelegt',f'{before}->{after}')

    print('\n== D  Staff-Login mit eigenem AJAX-Post ==')
    st,h,b6=go('/scp/login.php')
    chk(st==200,'GET scp/login.php 200',st)
    chk('cf-turnstile' in b6,'Widget im echten Staff-Login-Template')
    csp6=h.get('Content-Security-Policy','')
    chk('challenges.cloudflare.com' in csp6,'CSP auch auf login.header.php erweitert',csp6[:120])
    st,h,b7=go('/scp/login.php',{'ajax':'1','userid':STAFF_USER,'username':STAFF_USER,'passwd':STAFF_PASS},
               {'X-Requested-With':'XMLHttpRequest'})
    chk(st==403,'AJAX-Login ohne Token -> 403',st)
    chk('<!DOCTYPE' not in b7,'AJAX-Antwort ist ein Fragment, keine ganze HTML-Seite',b7[:80])
    chk('error' in b7.lower(),'Fragment enthält eine Fehlermeldung',b7[:120])
    st,h,b8=go('/scp/login.php',{'ajax':'1','userid':STAFF_USER,'username':STAFF_USER,
               'passwd':STAFF_PASS,'cf-turnstile-response':DUMMY},{'X-Requested-With':'XMLHttpRequest'})
    chk(st!=403,'AJAX-Login mit gültigem Token wird durchgelassen',st)
    chk('Sicherheitsprüfung nicht bestanden' not in b8,'osTicket übernimmt, Gate blockt nicht mehr')

    print('\n== E  Client-Login ==')
    st,h,b9=go('/login.php')
    chk('cf-turnstile' in b9,'Widget im echten Client-Login')
    st,h,b10=go('/login.php',{'luser':'kunde@example.com','lpasswd':'x'})
    chk(st==403,'Client-Login ohne Token -> 403',st)

    print('\n== F  Kill-Switch ==')
    open(f'{OST}/include/plugins/turnstile/DISABLED','w').write('')
    AJH={'X-Requested-With':'XMLHttpRequest','X-CSRFToken':csrf or '','Referer':BASE+'/open.php'}
    st,h,b11=go('/ajax.php/form/help-topic/1',hdrs=AJH)
    chk('cf-turnstile' not in b11,'DISABLED-Datei legt das Plugin still')
    st,h,b12=go('/scp/login.php',{'ajax':'1','username':'x','passwd':'y'},{'X-Requested-With':'XMLHttpRequest'})
    chk(st!=403,'Login nicht mehr blockiert -> Aussperrung aufhebbar',st)
    os.remove(f'{OST}/include/plugins/turnstile/DISABLED')
    st,h,b13=go('/ajax.php/form/help-topic/1',hdrs=AJH)
    chk('cf-turnstile' in b13,'nach Entfernen wieder aktiv')
finally:
    srv.terminate(); srv.wait()
print(f'\n{"-"*54}\nE2E gegen echtes osTicket 1.18.4   PASS: {P}  FAIL: {F}')
sys.exit(1 if F else 0)
