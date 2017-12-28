<h1> 설치방법 </h1>
<ol>
    <li>Sanctions 폴더를 <code>extensions/</code> 아래에 위치시킵니다.</li>
    <li>
        LocalSetting.php에 다음 코드를 추가합니다.
        <pre>wfLoadExtension( 'Sanctions' );</pre>
    </li>
    <li>update script를 실행합니다.</li>
    <li>
        위키의 찬성, 반대 틀에 다음을 추가합니다.
        <pre>&lt;span class="vote-agree-period">1&lt;/span>
&lt;span class="vote-agree">&lt;/span>
&lt;span class="vote-disagree">&lt;/span></pre>
    </li>
</ol>
<h1>요구사항</h1>
<ul>
    <li>extension:Flow</li>
</ul>
