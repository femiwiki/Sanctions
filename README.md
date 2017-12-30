<h1> 설치방법 </h1>
<ol>
    <li>Sanctions 폴더를 <code>extensions/</code> 아래에 위치시킵니다.</li>
    <li>
        LocalSetting.php에 다음 코드를 추가합니다.
        <pre>wfLoadExtension( 'Sanctions' );</pre>
    </li>
    <li>update script를 실행합니다.</li>
    <li>
        <p>위키의 찬성, 반대 틀에 다음을 추가합니다.</p>
        <pre>&lt;span class="sanction-vote-agree-period">1&lt;/span>
&lt;span class="sanction-vote-agree">&lt;/span>
&lt;span class="sanction-vote-disagree">&lt;/span></pre>
    </li>
</ol>
<h1>요구사항</h1>
<ul>
    <li>extension:Flow</li>
</ul>
<h1>설정</h1>
<p>위키의 다음 문서를 수정하여 설정을 변경할 수 있습니다.
<dl>
    <di>미디어위키:sanctions-voting-period</di><dd>의결 기간 일수입니다. 기본값 3</dd>
    <di>미디어위키:sanctions-voting-right-verification-period</di><dd>제재 절차 참가에 필요한 조건 중 가입한 지 n일 경과, n일 내 m 번 편집의 n, n일간 제재 기록 없음의 n입니다. 기본값 20</dd>
    <di>미디어위키:sanctions-voting-right-verification-edits</di><dd>제재 절차 참가에 필요한 조건 중 n일 내 m 번 편집의 m입니다. 기본값 3</dd>
</dl>
