<h1>Sanction 확장기능</h1>
<h2> 설치방법 </h2>
<ol>
    <li>Sanctions 폴더를 <code>extensions/</code> 아래에 위치시킵니다.</li>
    <li>
        <p>LocalSetting.php에 다음 코드를 추가합니다.</p>
        <pre>wfLoadExtension( 'Sanctions' );</pre>
    </li>
    <li>update script를 실행합니다.</li>
    <li>
        <p>위키의 찬성, 반대 틀에 다음을 추가합니다.</p>
        <ul>
            <li><code>&lt;span class="sanction-vote-agree-period"><i>숫자</i>&lt;/span></code></li>
            <li><code>&lt;span class="sanction-vote-agree">&lt;/span></code></li>
            <li><code>&lt;span class="sanction-vote-disagree">&lt;/span></code></li>
        </ul>
    </li>
</ol>
<h2>요구사항</h2>
<ul>
    <li>extension:Flow</li>
</ul>
<h2>설정</h2>
<p>위키의 다음 문서를 수정하여 설정을 변경할 수 있습니다.</p>
<dl>
    <di>sanctions-discussion-page-name</di><dd>제재안 게시 문서 이름입니다. 기본값 "페미위키토론:제재안에 대한 의결".</dd>
    <di>미디어위키:sanctions-voting-period</di><dd>의결 기간 일수입니다. Float형 기본값 3.</dd>
    <di>미디어위키:sanctions-voting-right-verification-period</di><dd>제재 절차 참가에 필요한 조건 중 가입한 지 n일 경과, n일 내 m 번 편집, n일간 제재 기록 없음의 n입니다. Float형 기본값 20.</dd>
    <di>미디어위키:sanctions-voting-right-verification-edits</di><dd>제재 절차 참가에 필요한 조건 중 n일 내 m 번 편집의 m입니다. Float형 기본값 3.</dd>
</dl>
